<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * A thin Asterisk Manager Interface client.
 *
 * Only three things are needed: authenticate, read event frames, and detect a
 * dead link. The stream is injected so the client can be exercised over a
 * socket pair in tests without any Asterisk instance.
 */
final class AmiClient implements AmiSource
{
    private AmiFrameReader $reader;

    /** @var resource */
    private $stream;

    /**
     * @param  resource  $stream
     */
    public function __construct($stream, private string $username, private string $secret)
    {
        $this->stream = $stream;
        $this->reader = new AmiFrameReader($stream);
    }

    /**
     * @return resource
     */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * @return resource
     */
    public static function connect(string $host, int $port, float $timeout = 10.0)
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            'tcp://'.$host.':'.$port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new \RuntimeException(sprintf('cannot reach AMI at %s:%d (%s)', $host, $port, $errstr !== '' ? $errstr : 'unknown error'));
        }

        stream_set_blocking($stream, true);
        // Readiness is driven by stream_select() in the runner; this timeout is
        // only a safety net for a half-open connection.
        stream_set_timeout($stream, 3600);

        return $stream;
    }

    public function login(string $events = 'call,reporting'): void
    {
        $this->write(sprintf(
            "Action: Login\r\nUsername: %s\r\nSecret: %s\r\nEvents: %s\r\n\r\n",
            $this->username,
            $this->secret,
            $events,
        ));

        $frame = $this->reader->readFrame();

        if ($frame === null || strtolower(trim((string) ($frame['Response'] ?? ''))) !== 'success') {
            throw new \RuntimeException('AMI login refused: '.(string) json_encode($frame));
        }
    }

    /**
     * The next event frame, skipping action responses. Null on EOF.
     *
     * @return array<string, string>|null
     */
    public function readEvent(): ?array
    {
        while (($frame = $this->reader->readFrame()) !== null) {
            if (isset($frame['Event'])) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * Keeps the link honest: a write to a closed socket fails, which the runner
     * turns into a reconnect.
     */
    public function ping(): bool
    {
        return $this->write("Action: Ping\r\n\r\n");
    }

    private function write(string $data): bool
    {
        $written = @fwrite($this->stream, $data);

        return $written !== false && $written > 0;
    }
}
