<?php

declare(strict_types=1);

/**
 * Copy this file to config.php and fill in the two secrets.
 *
 * The values must match the Laravel application exactly:
 *
 *   TELEPHONY_PROVISIONING_TOKEN   -> 'token'
 *   TELEPHONY_SIGNING_SECRET       -> 'signing_secret'
 *
 * config.php is never committed and is readable only by the service account.
 */

return [
    // Shared with the application (integrations.telephony.token).
    'token' => 'REPLACE_WITH_PROVISIONING_TOKEN',

    // Shared with the application (integrations.telephony.signing_secret).
    'signing_secret' => 'REPLACE_WITH_PROVISIONING_SIGNING_SECRET',

    // Requests older/newer than this many seconds are rejected (replay window).
    'timestamp_window' => 300,

    // Writable state: idempotency records, seen nonces, stored SIP passwords.
    // Must be listed in ReadWritePaths of the systemd unit.
    'state_dir' => '/var/lib/vodnox-telephony/state',

    'asterisk' => [
        'binary' => '/usr/sbin/asterisk',

        // Included by pjsip.conf as: #include vodnox/generated/*.conf
        'include_dir' => '/etc/asterisk/vodnox/generated',

        // Included by extensions.conf as: #include vodnox/dialplan/*.conf
        'dialplan_include_dir' => '/etc/asterisk/vodnox/dialplan',

        // The PJSIP transport the browser phone uses (WS/WSS).
        'transport' => 'transport-wss',

        // Codecs offered to WebRTC clients.
        'codecs' => 'opus,ulaw,alaw',

        // How long an internal extension rings before it is considered missed.
        'ring_timeout' => 30,
    ],
];
