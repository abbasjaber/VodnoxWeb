<?php

declare(strict_types=1);

/**
 * Copy this file to /etc/vodnox-telephony-events/config.php and fill in the
 * two secrets. It is never committed and is readable only by the service
 * account (root:asterisk 0640).
 *
 * The two secrets must match the Laravel application exactly:
 *
 *   ami.secret      -> the [vodnox-events] account in asterisk/manager.conf
 *   webhook.secret  -> TELEPHONY_WEBHOOK_SECRET in the application .env
 *
 * The webhook URL is the public application endpoint; it must be HTTPS and
 * resolvable from the media server, and its certificate must be trusted (the
 * forwarder never disables peer verification).
 */

return [
    'ami' => [
        // AMI must stay on loopback: the forwarder only ever reads.
        'host' => '127.0.0.1',
        'port' => 5038,

        // The read-only account from asterisk/manager.conf.snippet.
        'username' => 'vodnox-events',
        'secret' => 'REPLACE_WITH_AMI_SECRET',

        // Only the class the tracker needs (Newchannel/Newstate/Hangup).
        'events' => 'call',

        // Bounded so a hung connect cannot stall the loop forever.
        'connect_timeout' => 10.0,
    ],

    'webhook' => [
        // The Laravel webhook route (routes/api.php).
        'url' => 'https://vodnox.com/api/v1/telephony/webhook',
        'secret' => 'REPLACE_WITH_WEBHOOK_SECRET',

        'timeout' => 10.0,

        // How many spooled events one pass may hand over.
        'batch_limit' => 200,
    ],

    // Durable queue: written before the first attempt, so an outage can never
    // lose an event. Must be writable by the service account and listed in
    // ReadWritePaths of the systemd unit.
    'spool_dir' => '/var/lib/vodnox-telephony/events',

    // Seconds of silence before a Ping is sent to prove the link is alive.
    'ping_interval' => 15.0,
];
