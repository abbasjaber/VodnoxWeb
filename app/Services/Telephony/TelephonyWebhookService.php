<?php

namespace App\Services\Telephony;

use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;

/**
 * Inbound telephony webhook handling.
 *
 * Two guarantees:
 *  1. Authenticity — every request is verified with an HMAC-SHA256 signature
 *     over the raw body using the shared secret. Unsigned/again requests are
 *     rejected before any state is written.
 *  2. Idempotency — the provider may retry; a repeated `event_id` never
 *     creates a second row (enforced by the unique index and a guarded insert).
 */
class TelephonyWebhookService
{
    public function secret(): ?string
    {
        $secret = config('integrations.telephony.webhook_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function isConfigured(): bool
    {
        return $this->secret() !== null;
    }

    /**
     * Constant-time signature check over the raw request body.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = $this->secret();

        if ($secret === null || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * Persist an inbound event exactly once.
     *
     * @param  array<string, mixed>  $payload
     * @return array{event: WebhookEvent, duplicate: bool}
     */
    public function record(array $payload, ?string $signature = null): array
    {
        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $eventType = trim((string) ($payload['event_type'] ?? 'unknown'));

        $existing = WebhookEvent::query()->where('event_id', $eventId)->first();
        if ($existing) {
            return ['event' => $existing, 'duplicate' => true];
        }

        try {
            $event = WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'organization_id' => $payload['organization_id'] ?? null,
                'payload' => $payload,
                'signature' => $signature,
                'status' => 'processed',
                'occurred_at' => $this->occurredAt($payload),
            ]);
        } catch (QueryException $e) {
            // A concurrent duplicate slipped past the pre-check; the unique
            // index rejected it, so return the row that won the race.
            $event = WebhookEvent::query()->where('event_id', $eventId)->firstOrFail();

            return ['event' => $event, 'duplicate' => true];
        }

        return ['event' => $event, 'duplicate' => false];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function occurredAt(array $payload): \Illuminate\Support\Carbon
    {
        $raw = $payload['occurred_at'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return \Illuminate\Support\Carbon::parse($raw);
            } catch (\Throwable) {
                // Fall through to now().
            }
        }

        return now();
    }
}
