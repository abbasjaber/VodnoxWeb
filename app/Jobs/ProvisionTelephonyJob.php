<?php

namespace App\Jobs;

use App\Contracts\Telephony\TelephonyGateway;
use App\Models\Extension;
use App\Models\GatewayDevice;
use App\Models\GatewayLine;
use App\Models\Organization;
use App\Models\ProvisioningJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Throwable;

/**
 * Reconciles a telephony asset with the external telephony server.
 *
 * Guarantees:
 *  - Idempotency: a "synced" provisioning_jobs row with the same idempotency
 *    key short-circuits the run, so re-dispatching after a retry never
 *    duplicates server-side work.
 *  - Concurrency: WithoutOverlapping is keyed on the *resource*
 *    (organization / extension / gateway line), never on a revision digest, so
 *    a stale retry and a fresh edit of the same line cannot interleave.
 *  - Retry/backoff: transient gateway outages are retried; the row records a
 *    readable failure reason and can be retried from the UI.
 *
 * SIP passwords are never stored in the payload; for extension and gateway-line
 * operations the current (encrypted) credentials are read from the database at
 * run time.
 */
class ProvisionTelephonyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public int $organizationId,
        public array $payload = [],
        public string $idempotencyKey = '',
        public ?int $extensionId = null,
        public ?int $gatewayDeviceId = null,
        public ?int $gatewayLineId = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('telephony:'.$this->resourceKey()))->expireAfter(120),
        ];
    }

    /**
     * The lock key describes *what* is being changed, not *which revision* is
     * being applied, so an old revision can never win a race against a newer
     * one for the same asset.
     */
    public function resourceKey(): string
    {
        if ($this->gatewayLineId !== null) {
            return 'gateway-line:'.$this->organizationId.':'.($this->gatewayDeviceId ?? 0).':'.$this->gatewayLineId;
        }

        if ($this->extensionId !== null) {
            return 'extension:'.$this->organizationId.':'.$this->extensionId;
        }

        return 'organization:'.$this->organizationId;
    }

    public function handle(TelephonyGateway $gateway): void
    {
        $record = ProvisioningJob::firstOrCreate(
            ['idempotency_key' => $this->idempotencyKey],
            [
                'type' => $this->type,
                'organization_id' => $this->organizationId,
                'payload' => $this->payload,
                'status' => 'pending',
            ],
        );

        // Idempotency guard: already reconciled, do not call the gateway again.
        if ($record->status === 'synced') {
            return;
        }

        $record->forceFill(['status' => 'processing', 'error_message' => null])->save();

        try {
            $result = $this->callGateway($gateway);
        } catch (Throwable $e) {
            $record->forceFill([
                'status' => 'failed',
                'error_message' => $this->redactText($e->getMessage()),
            ])->save();

            $this->markFailed($this->redactText($e->getMessage()));

            throw $e;
        }

        $record->forceFill([
            'status' => 'synced',
            'error_message' => null,
            'completed_at' => now(),
            'payload' => array_merge($this->payload, ['result' => $this->redact($result)]),
        ])->save();

        $this->markSynced();
    }

    public function failed(Throwable $e): void
    {
        ProvisioningJob::query()
            ->where('idempotency_key', $this->idempotencyKey)
            ->update(['status' => 'failed', 'error_message' => $this->redactText($e->getMessage())]);

        $this->markFailed($this->redactText($e->getMessage()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function callGateway(TelephonyGateway $gateway): array
    {
        return match ($this->type) {
            'provision_organization' => $gateway->provisionOrganization($this->payload),
            'provision_extension' => $gateway->provisionExtension($this->extensionPayload()),
            'deprovision_extension' => $gateway->deprovisionExtension($this->payload),
            'deprovision_organization' => $gateway->deprovisionOrganization($this->payload),
            'provision_ivr' => $gateway->provisionIvr($this->payload),
            'provision_queue' => $gateway->provisionQueue($this->payload),
            'provision_gateway_line' => $gateway->provisionGatewayLine($this->gatewayLinePayload()),
            'deprovision_gateway_line' => $gateway->deprovisionGatewayLine($this->payload),
            // Unknown types are refused rather than silently degraded into an
            // organization write.
            default => throw new InvalidArgumentException('Unsupported telephony provisioning type: '.$this->type),
        };
    }

    /**
     * Inject the current credentials (kept out of the persisted payload) so a
     * rotation is reflected without ever writing the secret to the job row.
     *
     * @return array<string, mixed>
     */
    protected function extensionPayload(): array
    {
        if ($this->extensionId === null) {
            return $this->payload;
        }

        $extension = Extension::query()
            ->whereKey($this->extensionId)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (! $extension) {
            return $this->payload;
        }

        return array_merge($this->payload, [
            'sip_username' => $extension->sip_username,
            'sip_password' => (string) $extension->sip_password,
            'sip_domain' => $extension->sip_domain,
        ]);
    }

    /**
     * Read the live gateway-line credentials at execution time.
     *
     * The persisted payload carries identifiers and the revision only; the SIP
     * password is merged here so it can never be read back from
     * provisioning_jobs, a failed job or a log line.
     *
     * @return array<string, mixed>
     */
    protected function gatewayLinePayload(): array
    {
        if ($this->gatewayLineId === null) {
            return $this->payload;
        }

        $line = GatewayLine::query()
            ->whereKey($this->gatewayLineId)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (! $line) {
            return $this->payload;
        }

        return array_merge($this->payload, [
            'sip_username' => $line->sip_username,
            'sip_password' => (string) $line->sip_password,
            'sip_domain' => $line->sip_domain,
        ]);
    }

    protected function markSynced(): void
    {
        if ($this->type === 'provision_organization') {
            Organization::query()->whereKey($this->organizationId)->update([
                'telephony_status' => ($this->payload['enabled'] ?? true) ? 'synced' : 'disabled',
                'telephony_error' => null,
                'telephony_synced_at' => now(),
            ]);

            return;
        }

        if ($this->type === 'provision_gateway_line' || $this->type === 'deprovision_gateway_line') {
            $this->markGatewayLine(removed: $this->type === 'deprovision_gateway_line');

            return;
        }

        if ($this->extensionId === null) {
            return;
        }

        $enabled = (bool) ($this->payload['enabled'] ?? true);

        Extension::query()->whereKey($this->extensionId)
            ->where('organization_id', $this->organizationId)
            ->update([
                'provisioning_status' => match (true) {
                    $this->type === 'deprovision_extension' => 'removed',
                    $enabled => 'synced',
                    default => 'disabled',
                },
                'provisioning_error' => null,
                'provisioned_at' => $this->type === 'deprovision_extension' ? null : now(),
                'status' => $enabled ? 'active' : 'inactive',
            ]);
    }

    protected function markFailed(string $message): void
    {
        if ($this->type === 'provision_organization') {
            Organization::query()->whereKey($this->organizationId)->update([
                'telephony_status' => 'failed',
                'telephony_error' => $message,
            ]);

            return;
        }

        if ($this->type === 'provision_gateway_line' || $this->type === 'deprovision_gateway_line') {
            $this->markGatewayLine(removed: false, error: $message);

            return;
        }

        if ($this->extensionId === null) {
            return;
        }

        Extension::query()->whereKey($this->extensionId)
            ->where('organization_id', $this->organizationId)
            ->update([
                'provisioning_status' => 'failed',
                'provisioning_error' => $message,
            ]);
    }

    /**
     * Apply the result of a gateway-line reconciliation.
     *
     * The update is guarded on the revision carried in the payload so a retry
     * that finishes after a newer edit cannot write an older state back.
     */
    protected function markGatewayLine(bool $removed, ?string $error = null): void
    {
        if ($this->gatewayLineId === null) {
            return;
        }

        $revision = (int) ($this->payload['revision'] ?? 0);
        $query = GatewayLine::query()
            ->whereKey($this->gatewayLineId)
            ->where('organization_id', $this->organizationId)
            ->when($revision > 0, fn ($q) => $q->where('provisioning_revision', '<=', $revision));

        if ($error !== null) {
            $query->update([
                'provisioning_status' => 'failed',
                'provisioning_error' => $error,
            ]);

            $this->refreshDeviceStatus();

            return;
        }

        $enabled = (bool) ($this->payload['enabled'] ?? true);

        $query->update([
            'provisioning_status' => match (true) {
                $removed => 'removed',
                $enabled => 'synced',
                default => 'disabled',
            },
            'provisioning_error' => null,
            'provisioned_at' => $removed ? null : now(),
        ]);

        $this->refreshDeviceStatus();
    }

    /**
     * The device-level status is the aggregate of its lines, so a device is only
     * "synced" when every enabled line is.
     */
    protected function refreshDeviceStatus(): void
    {
        if ($this->gatewayDeviceId === null) {
            return;
        }

        $device = GatewayDevice::query()
            ->whereKey($this->gatewayDeviceId)
            ->where('organization_id', $this->organizationId)
            ->first();

        $device?->recomputeStatus();
    }

    /**
     * Remove any secret from a gateway reply before it is persisted.
     *
     * A simulated (or echoing) gateway may return the payload it received,
     * which includes the SIP password injected at run time. The secret must
     * never reach provisioning_jobs, so the stored result is stripped.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function redact(array $value): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if (self::isSecretKey((string) $key)) {
                continue;
            }

            $redacted[$key] = is_array($item) ? $this->redact($item) : $item;
        }

        return $redacted;
    }

    /**
     * Best-effort scrub of a secret-looking assignment from an error message
     * (e.g. a body echoed back inside an exception).
     */
    protected function redactText(string $message): string
    {
        return (string) preg_replace(
            '/("?(?:sip_)?password"?\s*[:=]\s*)("?)[^"\,}\s]+/i',
            '$1$2[redacted]',
            $message,
        );
    }

    private static function isSecretKey(string $key): bool
    {
        return in_array(strtolower($key), ['sip_password', 'sip_secret', 'password', 'secret', 'auth_password'], true);
    }
}
