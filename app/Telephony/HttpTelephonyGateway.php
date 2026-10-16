<?php

namespace App\Telephony;

use App\Contracts\Telephony\TelephonyGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP adapter for a real telephony control API. It is only bound when a server
 * with a base URL and token is configured (see AppServiceProvider). Credentials
 * come from the encrypted telephony_servers row, never from the request.
 */
class HttpTelephonyGateway implements TelephonyGateway
{
    /**
     * @param  array{base_url: string, token?: string, timeout?: int}  $config
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

    public function provisionIvr(array $payload): array
    {
        return $this->post('/ivr-menus', $payload);
    }

    public function provisionQueue(array $payload): array
    {
        return $this->post('/queues', $payload);
    }

    public function testConnection(): array
    {
        try {
            $response = $this->client()->get('/health');

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
        if (empty($this->config['base_url'])) {
            throw new RuntimeException('Telephony server base URL is not configured.');
        }

        $response = $this->client()->post($path, $payload);
        $response->throw();

        return $response->json() ?? [];
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::baseUrl($this->config['base_url'])
            ->timeout($this->config['timeout'] ?? 15)
            ->acceptJson();

        if (! empty($this->config['token'])) {
            $client = $client->withToken($this->config['token']);
        }

        return $client;
    }
}
