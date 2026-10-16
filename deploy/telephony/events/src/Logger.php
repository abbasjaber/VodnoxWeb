<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * Minimal logger writing one line per entry to a stream (stderr by default).
 * systemd captures it through StandardError/journal.
 */
final class Logger
{
    /** @var resource */
    private $stream;

    /**
     * @param  resource|null  $stream
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        fwrite($this->stream, sprintf(
            "[%s] %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            str_replace("\n", ' ', $message),
        ));
    }
}
