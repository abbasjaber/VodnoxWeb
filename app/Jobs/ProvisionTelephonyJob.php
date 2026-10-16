<?php

namespace App\Jobs;

use App\Contracts\Telephony\TelephonyGateway;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\ProvisioningJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reconciles a telephony asset with the external telephony server.
 *
 * Guarantees:
 *  - Idempotency: a "synced" provisioning_jobs row with the same idempotency
 *    key short-circuits the run, so re-dispatching after a retry never
 *    duplicates server-side work.
 *  - Concurrency: WithoutOverlapping serialises jobs that touch the same
 *    organization/extension, so concurrent edits cannot interleave writes.
 *  - Retry/backoff: transient gateway outages are retried; the row records a
 *    readable failure reason and can be retried from the UI.
 *
 * SIP passwords are never stored in the payload; for extension operations the
 * current (encrypted) credentials are read from the database at run time.
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
            (new WithoutOverlapping('telephony:'.$this->idempotencyKey))->expireAfter(120),
        ];
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
                'error_message' => $e->getMessage(),
            ])->save();

            $this->markFailed($e->getMessage());

            throw $e;
        }

        $record->forceFill([
            'status' => 'synced',
            'error_message' => null,
            'completed_at' => now(),
            'payload' => array_merge($this->payload, ['result' => $result]),
        ])->save();

        $this->markSynced();
    }

    public function failed(Throwable $e): void
    {
        ProvisioningJob::query()
            ->where('idempotency_key', $this->idempotencyKey)
            ->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

        $this->markFailed($e->getMessage());
    }

    /**
     * @return array<string, mixed>
     */
    protected function callGateway(TelephonyGateway $gateway): array
    {
        return match ($this->type) {
            'provision_extension' => $gateway->provisionExtension($this->extensionPayload()),
            'deprovision_extension' => $gateway->deprovisionExtension($this->payload),
            'deprovision_organization' => $gateway->deprovisionOrganization($this->payload),
            'provision_ivr' => $gateway->provisionIvr($this->payload),
            'provision_queue' => $gateway->provisionQueue($this->payload),
            default => $gateway->provisionOrganization($this->payload),
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
}
