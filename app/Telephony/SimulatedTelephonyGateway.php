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

    public function deprovisionExtension(array $payload): array
    {
        return $this->result('deprovision_extension', $payload);
    }

    public function deprovisionOrganization(array $payload): array
    {
        return $this->result('deprovision_organization', $payload);
    }

    public function provisionIvr(array $payload): array
    {
        return $this->result('provision_ivr', $payload);
    }

    public function provisionQueue(array $payload): array
    {
        return $this->result('provision_queue', $payload);
    }

    public function provisionGatewayLine(array $payload): array
    {
        return $this->result('provision_gateway_line', $payload);
    }

    public function deprovisionGatewayLine(array $payload): array
    {
        return $this->result('deprovision_gateway_line', $payload);
    }

    /**
     * Simulation never claims a device is registered: it reports an explicit
     * unknown so no UI can display a fake green status.
     */
    public function gatewayStatus(array $payload): array
    {
        $lines = [];

        foreach ((array) ($payload['sip_usernames'] ?? []) as $username) {
            $lines[(string) $username] = [
                'state' => 'unknown',
                'registered' => false,
                'source' => 'simulated',
            ];
        }

        return [
            'simulated' => true,
            'source' => 'simulated',
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'lines' => $lines,
        ];
    }

    public function capabilities(): array
    {
        return [
            'agent_version' => 'simulated',
            'capabilities' => ['organizations', 'extensions', 'gateway_lines', 'gateway_status'],
            'simulated' => true,
        ];
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
