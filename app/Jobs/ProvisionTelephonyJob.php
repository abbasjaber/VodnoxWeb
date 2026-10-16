<?php

namespace App\Jobs;

use App\Contracts\Telephony\TelephonyGateway;
use App\Models\Extension;
use App\Models\ProvisioningJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reconciles a telephony asset with the external telephony server.
 *
 * The job is idempotent: a previously "synced" provisioning_jobs row with the
 * same idempotency key short-circuits the run, so re-dispatching after a
 * mutation never duplicates server-side work.
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

            throw $e;
        }

        $record->forceFill([
            'status' => 'synced',
            'error_message' => null,
            'completed_at' => now(),
            'payload' => array_merge($this->payload, ['result' => $result]),
        ])->save();

        if ($this->extensionId !== null) {
            Extension::query()->whereKey($this->extensionId)
                ->where('organization_id', $this->organizationId)
                ->update(['status' => 'provisioned']);
        }
    }

    public function failed(Throwable $e): void
    {
        ProvisioningJob::query()
            ->where('idempotency_key', $this->idempotencyKey)
            ->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function callGateway(TelephonyGateway $gateway): array
    {
        return match ($this->type) {
            'provision_extension' => $gateway->provisionExtension($this->payload),
            'provision_ivr' => $gateway->provisionIvr($this->payload),
            'provision_queue' => $gateway->provisionQueue($this->payload),
            default => $gateway->provisionOrganization($this->payload),
        };
    }
}
