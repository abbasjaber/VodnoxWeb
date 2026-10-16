<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * HTTP delivery seam, so the publisher's retry/reject policy is testable
 * without touching the network.
 */
interface WebhookTransport
{
    /**
     * @param  array<int, string>  $headers
     * @return array{status: int|null, body: string}  status is null on a transport failure
     */
    public function post(string $url, array $headers, string $body, float $timeout): array;
}
