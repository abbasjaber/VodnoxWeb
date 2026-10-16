<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * Hands spooled events to the Laravel webhook.
 *
 * Delivery policy, and why:
 *  - 2xx — the receiver accepted the event (a duplicate is a 2xx there too), so
 *    the entry is removed. A crash-and-redeliver cycle is therefore safe.
 *  - 4xx — the request itself was refused (bad signature, invalid payload).
 *    Retrying cannot ever succeed, so the entry is quarantined instead of
 *    blocking every later event behind it forever.
 *  - 5xx or a transport failure (status null, e.g. DNS/TLS/timeout) — transient.
 *    The batch stops here so events keep their order, and the next pass retries.
 */
final class EventPublisher
{
    public function __construct(
        private Spool $spool,
        private WebhookTransport $transport,
        private WebhookSigner $signer,
        private string $url,
        private Logger $logger,
        private float $timeout = 10.0,
        private int $batchLimit = 200,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return bool  false when an identical event is already queued
     */
    public function enqueue(array $payload): bool
    {
        return $this->spool->enqueue($payload);
    }

    /**
     * @return int  the number of events accepted by the receiver
     */
    public function deliverPending(): int
    {
        $delivered = 0;

        foreach ($this->spool->pending($this->batchLimit) as $entry) {
            $raw = json_encode($entry['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (! is_string($raw)) {
                $this->logger->error('a spooled event cannot be encoded; quarantined');
                $this->spool->reject($entry['file']);

                continue;
            }

            $result = $this->transport->post($this->url, $this->signer->headers($raw), $raw, $this->timeout);
            $status = $result['status'];

            if ($status !== null && $status >= 200 && $status < 300) {
                $this->spool->complete($entry['file']);
                $delivered++;

                continue;
            }

            if ($status !== null && $status >= 400 && $status < 500) {
                $this->logger->error(sprintf(
                    'the receiver refused event %s (HTTP %d); quarantined',
                    (string) ($entry['payload']['event_id'] ?? '?'),
                    $status,
                ));
                $this->spool->reject($entry['file']);

                continue;
            }

            $this->logger->warning(sprintf(
                'delivery failed (%s); %d event(s) stay queued and keep their order',
                $status === null ? 'transport error' : 'HTTP '.$status,
                $this->spool->size(),
            ));

            break;
        }

        return $delivered;
    }

    public function spoolSize(): int
    {
        return $this->spool->size();
    }
}
