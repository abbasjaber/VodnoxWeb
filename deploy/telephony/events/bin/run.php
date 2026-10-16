#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * VODNOX call-event forwarder.
 *
 * Runs on the Asterisk host as a systemd service. It reads call events from
 * AMI over loopback, turns them into VODNOX call events, spools them on disk,
 * and posts them signed to the Laravel webhook.
 *
 * Configuration lives outside this directory (never in the repository):
 *     /etc/vodnox-telephony-events/config.php
 * Override the location with the VODNOX_EVENTS_CONFIG environment variable.
 */

use Vodnox\Telephony\Events\AmiClient;
use Vodnox\Telephony\Events\CallTracker;
use Vodnox\Telephony\Events\EventPublisher;
use Vodnox\Telephony\Events\Logger;
use Vodnox\Telephony\Events\Runner;
use Vodnox\Telephony\Events\Spool;
use Vodnox\Telephony\Events\StreamTransport;
use Vodnox\Telephony\Events\WebhookSigner;

require __DIR__.'/bootstrap.php';

$logger = new Logger();

$path = getenv('VODNOX_EVENTS_CONFIG');

if (! is_string($path) || $path === '') {
    $path = '/etc/vodnox-telephony-events/config.php';
}

if (! is_file($path)) {
    $logger->error('missing configuration file: '.$path);
    exit(1);
}

/** @var array<string, mixed> $config */
$config = require $path;

$url = trim((string) ($config['webhook']['url'] ?? ''));
$webhookSecret = (string) ($config['webhook']['secret'] ?? '');
$amiSecret = (string) ($config['ami']['secret'] ?? '');

// Fail fast and loudly: a service that "runs" with no secret would silently
// drop every call event instead of reporting the misconfiguration.
if ($url === '' || $webhookSecret === '' || $amiSecret === '') {
    $logger->error('webhook.url, webhook.secret and ami.secret must all be set in '.$path);
    exit(1);
}

$publisher = new EventPublisher(
    new Spool((string) ($config['spool_dir'] ?? '/var/lib/vodnox-telephony/events')),
    new StreamTransport(),
    new WebhookSigner($webhookSecret),
    $url,
    $logger,
    (float) ($config['webhook']['timeout'] ?? 10.0),
    (int) ($config['webhook']['batch_limit'] ?? 200),
);

$runner = new Runner(
    $logger,
    new CallTracker(),
    $publisher,
    (string) ($config['ami']['events'] ?? 'call'),
    (float) ($config['ping_interval'] ?? 15.0),
);

$host = (string) ($config['ami']['host'] ?? '127.0.0.1');
$port = (int) ($config['ami']['port'] ?? 5038);
$connectTimeout = (float) ($config['ami']['connect_timeout'] ?? 10.0);
$username = (string) ($config['ami']['username'] ?? '');
$spoolSize = $publisher->spoolSize();

$logger->info(sprintf(
    'starting: AMI %s:%d -> %s (%d event(s) pending at start)',
    $host,
    $port,
    $url,
    $spoolSize,
));

$runner->run(
    static fn (): AmiClient => new AmiClient(
        AmiClient::connect($host, $port, $connectTimeout),
        $username,
        $amiSecret,
    ),
);
