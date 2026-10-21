<?php

namespace Tests\Support;

use App\Contracts\Telephony\TelephonyGateway;

/**
 * In-memory telephony gateway used to drive the gateway provisioning behaviour
 * deterministically.
 *
 * A simulated gateway cannot express the interesting cases (an old agent that
 * omits a capability, an unreachable monitoring source, a reload that fails), so
 * tests bind this double instead and control exactly what the "server" answers.
 * Nothing here performs network calls.
 */
class FakeTelephonyGateway implements TelephonyGateway
{
    /** @var array<string, mixed> */
    public array $capabilitiesResponse = [
        'agent_version' => '1.3.0',
        'capabilities' => ['organizations', 'extensions', 'gateway_lines', 'gateway_routing', 'gateway_status'],
    ];

    /** @var array<string, mixed> */
    public array $statusResponse = ['source' => 'agent', 'lines' => []];

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    /**
     * When set, the named operations throw, so the failure paths can be tested.
     *
     * @var array<int, string>
     */
    public array $fail = [];

    /**
     * Echo the exact payload back, so tests can prove the run-time secret is
     * stripped before it is persisted.
     */
    public bool $echo = false;

    /** @param array<string, mixed>|null $capabilities */
    public function __construct(?array $capabilities = null, ?array $status = null)
    {
        if ($capabilities !== null) {
            $this->capabilitiesResponse = $capabilities;
        }

        if ($status !== null) {
            $this->statusResponse = $status;
        }
    }

    public function provisionOrganization(array $payload): array
    {
        return $this->record('provisionOrganization', $payload);
    }

    public function provisionExtension(array $payload): array
    {
        return $this->record('provisionExtension', $payload);
    }

    public function deprovisionExtension(array $payload): array
    {
        return $this->record('deprovisionExtension', $payload);
    }

    public function deprovisionOrganization(array $payload): array
    {
        return $this->record('deprovisionOrganization', $payload);
    }

    public function provisionIvr(array $payload): array
    {
        return $this->record('provisionIvr', $payload);
    }

    public function provisionQueue(array $payload): array
    {
        return $this->record('provisionQueue', $payload);
    }

    public function provisionGatewayLine(array $payload): array
    {
        return $this->record('provisionGatewayLine', $payload);
    }

    public function deprovisionGatewayLine(array $payload): array
    {
        return $this->record('deprovisionGatewayLine', $payload);
    }

    public function gatewayStatus(array $payload): array
    {
        $this->calls[] = ['gatewayStatus', $payload];

        return $this->statusResponse;
    }

    public function capabilities(): array
    {
        return $this->capabilitiesResponse;
    }

    public function testConnection(): array
    {
        return ['ok' => true, 'status' => 'connected'];
    }

    public function isSimulated(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function record(string $operation, array $payload): array
    {
        $this->calls[] = [$operation, $payload];

        if (in_array($operation, $this->fail, true)) {
            throw new \RuntimeException($operation.' failed (reload rejected)');
        }

        return $this->echo
            ? array_merge(['ok' => true], $payload)
            : ['ok' => true, 'operation' => $operation];
    }
}
