<?php

declare(strict_types=1);

namespace Vodnox\Telephony\Events;

/**
 * HTTPS transport built on PHP streams, so the service needs no ext-curl.
 *
 * Certificate and host-name verification stay ON: the caller must never be
 * able to talk to the application over a downgraded or spoofed connection.
 */
final class StreamTransport implements WebhookTransport
{
    public function post(string $url, array $headers, string $body, float $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status = null;

        if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $matches) === 1) {
            $status = (int) $matches[1];
        }

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
        ];
    }
}
