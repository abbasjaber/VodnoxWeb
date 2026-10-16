<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * Durable, ordered, on-disk queue of undelivered call events.
 *
 * An event is written to disk *before* the first delivery attempt, so a crash,
 * a restart, or a Laravel outage can never lose it. The file name carries a
 * microsecond sequence prefix so the queue is drained in the order the events
 * happened, while the event id inside the name keeps enqueueing idempotent — a
 * redelivered event is a no-op on the receiver.
 */
final class Spool
{
    /**
     * Tiebreaker for events written inside the same microsecond, so two events
     * queued back to back can never be reordered by the file name.
     */
    private static int $sequence = 0;

    private string $dir;

    private string $rejectedDir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        $this->rejectedDir = $this->dir.'/rejected';

        foreach ([$this->dir, $this->rejectedDir] as $path) {
            if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
                throw new \RuntimeException('cannot create the spool directory: '.$path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return bool  false when the event was already queued
     */
    public function enqueue(array $payload): bool
    {
        $eventId = $this->safeId($payload);

        if ($this->exists($eventId)) {
            return false;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($json)) {
            throw new \RuntimeException('cannot encode the event payload');
        }

        $file = $this->dir.'/'.self::nextName($eventId).'.json';
        $tmp = $file.'.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('cannot write the spool entry: '.$tmp);
        }

        // Atomic publish: readers never observe a partial file.
        if (! @rename($tmp, $file)) {
            @unlink($tmp);

            throw new \RuntimeException('cannot publish the spool entry: '.$file);
        }

        return true;
    }

    /**
     * Oldest first, so events keep their original order.
     *
     * @return array<int, array{file: string, payload: array<string, mixed>}>
     */
    public function pending(int $limit = 200): array
    {
        $files = glob($this->dir.'/*.json');

        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        $entries = [];

        foreach (array_slice($files, 0, $limit) as $file) {
            $raw = @file_get_contents($file);
            $payload = is_string($raw) ? json_decode($raw, true) : null;

            if (! is_array($payload)) {
                // Unreadable or corrupt: quarantine instead of blocking the queue.
                $this->reject($file);

                continue;
            }

            $entries[] = ['file' => $file, 'payload' => $payload];
        }

        return $entries;
    }

    public function complete(string $file): void
    {
        @unlink($file);
    }

    public function reject(string $file): void
    {
        @rename($file, $this->rejectedDir.'/'.basename($file));
    }

    public function size(): int
    {
        $files = glob($this->dir.'/*.json');

        return $files === false ? 0 : count($files);
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * The event id, sanitised so it can only ever be a file name fragment.
     *
     * @param  array<string, mixed>  $payload
     */
    private function safeId(array $payload): string
    {
        $eventId = (string) ($payload['event_id'] ?? '');

        if ($eventId === '') {
            throw new \InvalidArgumentException('an event must carry an event_id');
        }

        return (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $eventId);
    }

    /**
     * The sequence prefix differs between entries, so idempotency is decided by
     * the event id suffix rather than by a single known path.
     */
    private function exists(string $safeId): bool
    {
        $matches = glob($this->dir.'/*-'.$safeId.'.json');

        return $matches !== false && $matches !== [];
    }

    /**
     * "<microseconds><counter>-<event id>": lexicographic order is insertion
     * order, which is what lets a failed batch be retried without an event
     * overtaking an earlier one.
     */
    private static function nextName(string $eventId): string
    {
        return sprintf(
            '%016d%06d-%s',
            (int) round(microtime(true) * 1_000_000),
            self::$sequence++ % 1_000_000,
            $eventId,
        );
    }
}
