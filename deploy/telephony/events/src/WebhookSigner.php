<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * Signs outbound call events.
 *
 * This mirrors TelephonyWebhookService::verifySignature() exactly:
 *
 *     hex(hmac_sha256(rawBody, sharedSecret))
 *
 * so the receiving Laravel endpoint accepts the request and any tampering with
 * the body (or a missing signature) is rejected before state is written.
 */
final class WebhookSigner
{
    public const HEADER = 'X-VODNOX-Signature';

    public function __construct(private string $secret)
    {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('the webhook secret must not be empty');
        }
    }

    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->secret);
    }

    /**
     * @return array<int, string> HTTP header lines ("Name: value")
     */
    public function headers(string $rawBody): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            self::HEADER.': '.$this->sign($rawBody),
            'User-Agent: vodnox-telephony-events/1.0',
        ];
    }
}
