<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * Reads AMI events from one connection and feeds the spool.
 *
 * The runner never blocks forever on a read: it waits for readability with
 * stream_select(), so a quiet line still gets its spool drained and its link
 * checked with a Ping. When the link drops it returns, and the supervisor
 * reconnects with a bounded backoff — an Asterisk restart must not turn into a
 * busy loop.
 *
 * On (re)connect the tracker is reset: frames from before the connection are
 * gone, and reopening a half-seen call would corrupt the log.
 */
final class Runner
{
    public function __construct(
        private Logger $logger,
        private CallTracker $tracker,
        private EventPublisher $publisher,
        private string $events = 'call',
        private float $pingInterval = 15.0,
        private float $idleSeconds = 0.5,
    ) {}

    /**
     * Consume one AMI connection until it drops.
     *
     * @return int  the number of frames processed
     *
     * @throws \RuntimeException when the stream becomes unusable
     */
    public function consume(AmiSource $source): int
    {
        $source->login($this->events);
        $this->tracker->forget();

        // A previous outage may have left a backlog behind.
        $this->publisher->deliverPending();

        $stream = $source->stream();
        $frames = 0;
        $lastPing = microtime(true);

        $seconds = (int) $this->idleSeconds;
        $microseconds = (int) round(($this->idleSeconds - $seconds) * 1_000_000);

        while (true) {
            $read = [$stream];
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

            if ($ready === false) {
                throw new \RuntimeException('the AMI stream is no longer usable');
            }

            if ($ready === 0) {
                // Nothing arrived: drain the spool and keep the link honest.
                $this->publisher->deliverPending();

                if (microtime(true) - $lastPing >= $this->pingInterval) {
                    if (! $source->ping()) {
                        throw new \RuntimeException('the AMI link is not writable');
                    }

                    $lastPing = microtime(true);
                }

                continue;
            }

            $frame = $source->readEvent();

            if ($frame === null) {
                // EOF: Asterisk closed the connection.
                return $frames;
            }

            $frames++;

            foreach ($this->tracker->ingest($frame) as $event) {
                $this->publisher->enqueue($event);
            }

            $this->publisher->deliverPending();
        }
    }

    /**
     * Supervise the connection: connect, consume, retry with a capped backoff.
     *
     * @param  callable(): AmiSource  $connect
     */
    public function run(callable $connect): void
    {
        $backoff = 1;

        while (true) {
            try {
                $frames = $this->consume($connect());
                $this->logger->info($frames.' frame(s) consumed; the AMI link closed, reconnecting');
                $backoff = 1;
            } catch (\Throwable $e) {
                $this->logger->error('the AMI consumer failed: '.$e->getMessage());
                $this->tracker->forget();
            }

            // Deliver whatever is spooled even while Asterisk is unreachable.
            try {
                $this->publisher->deliverPending();
            } catch (\Throwable $e) {
                $this->logger->error('delivery failed: '.$e->getMessage());
            }

            sleep($backoff);
            $backoff = min($backoff * 2, 30);
        }
    }
}
