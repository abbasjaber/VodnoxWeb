<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * Reads the Asterisk Manager Interface framing: "Key: Value" lines terminated
 * by a blank line.
 *
 * The AMI never changes mid-frame, so a frame is only ever returned once its
 * terminating blank line has been read; a partial frame at EOF is still
 * returned so a final event is not silently dropped on shutdown.
 */
final class AmiFrameReader
{
    /** @var resource */
    private $stream;

    /**
     * @param  resource  $stream
     */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * @return array<string, string>|null  null only when the stream is at EOF
     */
    public function readFrame(): ?array
    {
        $frame = [];

        while (true) {
            $line = fgets($this->stream);

            if ($line === false) {
                return $frame === [] ? null : $frame;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                // Tolerate stray separators between frames.
                if ($frame === []) {
                    continue;
                }

                return $frame;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $frame[trim($key)] = ltrim($value, ' ');
        }
    }
}
