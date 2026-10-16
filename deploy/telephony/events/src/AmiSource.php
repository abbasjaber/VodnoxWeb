<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * The AMI connection seam the runner depends on.
 *
 * Keeping the connection behind an interface lets the runner be exercised over
 * a socket pair in tests — the real AmiClient and a fake both satisfy it — and
 * keeps the runner free of any socket-creation policy.
 */
interface AmiSource
{
    /**
     * The underlying stream, so the runner can wait for readability with
     * stream_select() instead of blocking reads with a timeout.
     *
     * @return resource
     */
    public function stream();

    /**
     * Authenticate and subscribe to the given AMI event classes.
     *
     * @throws \RuntimeException when the login is refused
     */
    public function login(string $events): void;

    /**
     * The next event frame, or null when the connection reached EOF.
     *
     * @return array<string, string>|null
     */
    public function readEvent(): ?array;

    /**
     * A cheap write used to detect a dead link while the line is quiet.
     */
    public function ping(): bool;
}
