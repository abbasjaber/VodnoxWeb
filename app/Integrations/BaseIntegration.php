<?php

namespace App\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base class for all external service integrations.
 *
 * Provides a consistent pattern for:
 *  - HTTP requests via the Laravel HTTP Client with configurable timeouts.
 *  - Structured logging to the dedicated "integrations" channel.
 *  - Redaction of secrets (passwords, tokens, API keys, Authorization
 *    headers) before anything is written to the logs.
 *  - Handling of connection failures, timeouts, and non-success responses.
 *
 * Idempotency note: subclasses MUST NOT enable automatic retries for
 * operations whose side effects may be repeated (e.g. initiating a call,
 * charging a customer). Retries are only safe for demonstrably idempotent
 * actions and must be configured explicitly per integration.
 */
abstract class BaseIntegration
{
    /**
     * Configuration key for this integration under config/integrations.php.
     */
    abstract protected function configKey(): string;

    /**
     * Build a pre-configured HTTP client for this integration.
     */
    protected function client(): PendingRequest
    {
        $config = $this->config();
        $defaults = config('integrations.defaults');

        $client = Http::baseUrl($config['base_url'] ?? '')
            ->timeout($defaults['timeout'])
            ->connectTimeout($defaults['connect_timeout'])
            ->withHeaders($this->defaultHeaders());

        if (! empty($config['token'])) {
            $client->withToken($config['token']);
        }

        return $client;
    }

    /**
     * Get the integration configuration from config/integrations.php.
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return config("integrations.{$this->configKey()}", []);
    }

    /**
     * Default headers sent with every request.
     *
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Log an integration event with secret redaction applied.
     *
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel(config('integrations.log_channel'))
            ->{$level}("[{$this->configKey()}] {$message}", $this->redact($context));
    }

    /**
     * Redact sensitive values before logging.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function redact(array $data): array
    {
        $sensitive = [
            'password', 'passwd', 'pwd', 'secret', 'token', 'api_key',
            'apiKey', 'authorization', 'auth', 'access_token', 'refresh_token',
            'client_secret',
        ];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);

                continue;
            }

            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $data[$key] = '***REDACTED***';
            }
        }

        return $data;
    }

    /**
     * Determine whether an HTTP response is acceptable.
     * Override to enforce integration-specific success criteria.
     */
    protected function isSuccessful(Response $response): bool
    {
        return $response->successful();
    }

    /**
     * Handle a failed response by logging and throwing.
     *
     * @throws \RuntimeException
     */
    protected function handleFailure(Response $response, string $context = ''): void
    {
        $this->log('error', "HTTP request failed {$context}", [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \RuntimeException(
            "Integration [{$this->configKey()}] request failed: HTTP {$response->status()}"
        );
    }

    /**
     * Handle a connection exception (timeout, DNS failure, etc.).
     *
     * @throws \RuntimeException
     */
    protected function handleConnectionError(Throwable $e, string $context = ''): void
    {
        $this->log('error', "Connection error {$context}", [
            'error' => $e->getMessage(),
        ]);

        throw new \RuntimeException(
            "Integration [{$this->configKey()}] connection error: {$e->getMessage()}",
            0,
            $e
        );
    }
}
