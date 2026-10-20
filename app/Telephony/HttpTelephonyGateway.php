<?php

namespace App\Telephony;

use App\Contracts\Telephony\TelephonyGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HTTP adapter for the VODNOX telephony provisioning agent.
 *
 * Every request is authenticated the same way the agent verifies it:
 *
 *   Authorization:     Bearer <token>
 *   X-VODNOX-Timestamp <unix seconds>
 *   X-VODNOX-Nonce     <random, single-use>
 *   X-VODNOX-Signature sha256=<hmac over "timestamp.nonce.rawBody">
 *   Idempotency-Key    <stable key for the operation>
 *
 * The signature covers the exact bytes that are sent, so the agent can reject
 * replays (fresh nonce + timestamp window) and tampering before any state is
 * written. Credentials come from config/encrypted rows, never from a request.
 */
class HttpTelephonyGateway implements TelephonyGateway
{
    /**
     * @param  array{base_url: string, token?: string, signing_secret?: string, timeout?: int, connect_timeout?: int, verify?: bool}  $config
     */
    public function __construct(private readonly array $config) {}

    public function provisionOrganization(array $payload): array
    {
        return $this->post('/organizations', $payload);
    }

    public function provisionExtension(array $payload): array
    {
        return $this->post('/extensions', $payload);
    }

    public function deprovisionExtension(array $payload): array
    {
        return $this->delete('/extensions/'.$this->identifier($payload));
    }

    public function deprovisionOrganization(array $payload): array
    {
        return $this->delete('/organizations/'.$this->identifier($payload));
    }

    public function provisionIvr(array $payload): array
    {
        return $this->post('/ivr-menus', $payload);
    }

    public function provisionQueue(array $payload): array
    {
        return $this->post('/queues', $payload);
    }

    public function provisionGatewayLine(array $payload): array
    {
        return $this->post('/gateway-lines', $payload);
    }

    public function deprovisionGatewayLine(array $payload): array
    {
        return $this->delete('/gateway-lines/'.$this->identifier($payload));
    }

    public function gatewayStatus(array $payload): array
    {
        return $this->post('/gateway-status', $payload);
    }

    public function capabilities(): array
    {
        try {
            $body = $this->get('/capabilities');

            return [
                'agent_version' => isset($body['agent_version']) ? (string) $body['agent_version'] : null,
                'capabilities' => is_array($body['capabilities'] ?? null) ? array_values($body['capabilities']) : [],
            ];
        } catch (\Throwable) {
            // An agent that predates capability negotiation (404), rejects the
            // call or is unreachable is reported as "no capabilities" — never
            // as support for a feature it does not have.
            return ['agent_version' => null, 'capabilities' => []];
        }
    }

    public function testConnection(): array
    {
        try {
            // The agent authenticates every route, including this read-only
            // probe, so the health check is signed over an empty body just like
            // the mutation calls. An unsigned GET is rejected by the agent.
            $response = $this->signed('')->get('/health');

            return [
                'ok' => $response->successful(),
                'status' => $response->successful() ? 'connected' : 'error',
                'message' => $response->successful() ? null : 'HTTP '.$response->status(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function isSimulated(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $body = $body === false ? '{}' : $body;

        $response = $this->signed($body)
            ->withHeaders([
                'Idempotency-Key' => $this->idempotencyKey($path, $payload),
            ])
            ->withBody($body, 'application/json')
            ->post($path);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * A signed read-only GET (the agent authenticates every route, including
     * reads, over an empty body).
     *
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = $this->signed('')->get($path);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $path): array
    {
        $body = '';

        $response = $this->signed($body)
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey($path, [])])
            ->withBody($body, 'application/json')
            ->delete($path);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Attach the timestamp/nonce/signature trio for the given raw body.
     */
    private function signed(string $body): PendingRequest
    {
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $secret = (string) ($this->config['signing_secret'] ?? '');

        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);

        return $this->client()->withHeaders([
            'X-VODNOX-Timestamp' => $timestamp,
            'X-VODNOX-Nonce' => $nonce,
            'X-VODNOX-Signature' => $signature,
        ]);
    }

    /**
     * A stable key so retries of the same content are collapsed by the agent.
     */
    private function idempotencyKey(string $path, array $payload): string
    {
        return 'vodnox:'.substr(sha1($path.'|'.json_encode($payload)), 0, 40);
    }

    /**
     * The path identifier used for deprovisioning (sip_username or org code).
     *
     * @param  array<string, mixed>  $payload
     */
    private function identifier(array $payload): string
    {
        $value = $payload['sip_username'] ?? $payload['context'] ?? $payload['code'] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Missing identifier for deprovisioning.');
        }

        return rawurlencode($value);
    }

    private function client(): PendingRequest
    {
        if (empty($this->config['base_url'])) {
            throw new RuntimeException('Telephony provisioning URL is not configured.');
        }

        $client = Http::baseUrl($this->config['base_url'])
            ->timeout($this->config['timeout'] ?? 15)
            ->connectTimeout($this->config['connect_timeout'] ?? 5)
            ->acceptJson();

        if (! empty($this->config['token'])) {
            $client = $client->withToken($this->config['token']);
        }

        return $client;
    }
}
