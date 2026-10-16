<?php

namespace App\Telephony;

use App\Contracts\Telephony\TelephonyGateway;

/**
 * Explicit simulation used until real telephony server credentials are provided.
 * Returns deterministic, clearly-marked simulated responses and performs no
 * network calls whatsoever.
 */
class SimulatedTelephonyGateway implements TelephonyGateway
{
    public function provisionOrganization(array $payload): array
    {
        return $this->result('provision_organization', $payload);
    }

    public function provisionExtension(array $payload): array
    {
        return $this->result('provision_extension', $payload);
    }

    public function provisionIvr(array $payload): array
    {
        return $this->result('provision_ivr', $payload);
    }

    public function provisionQueue(array $payload): array
    {
        return $this->result('provision_queue', $payload);
    }

    public function testConnection(): array
    {
        return [
            'ok' => true,
            'status' => 'simulated',
            'message' => 'Simulation mode: no telephony server configured.',
        ];
    }

    public function isSimulated(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $operation, array $payload): array
    {
        return [
            'simulated' => true,
            'operation' => $operation,
            'reference' => 'sim-'.substr(sha1($operation.json_encode($payload)), 0, 16),
            'payload' => $payload,
        ];
    }
}
