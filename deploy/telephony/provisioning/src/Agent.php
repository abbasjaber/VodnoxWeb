<?php

declare(strict_types=1);

namespace Vodnox\Telephony;

use RuntimeException;

/**
 * Thrown for every request the agent refuses. The status code is returned to
 * the caller unchanged so the Laravel side can classify the failure.
 */
final class Rejected extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}

/**
 * VODNOX telephony provisioning agent.
 *
 * A deliberately small, dependency-free HTTP service that owns the only two
 * privileged things on the media server:
 *
 *   1. the generated Asterisk configuration for each organization/extension;
 *   2. the non-disruptive reload commands for that configuration.
 *
 * Security model
 * --------------
 *  - Authenticated by a bearer token AND an HMAC-SHA256 signature over the
 *    exact request bytes, with a bounded timestamp window, a single-use nonce
 *    (replay protection) and a stable Idempotency-Key (retry collapse).
 *  - It never talks to AMI/ARI, never exposes a shell, never accepts a path
 *    from the caller: file names are derived from a validated SIP username.
 *  - Writes are atomic (temp file + rename) so a crash can never leave a
 *    half-written configuration behind.
 *  - Reloads are per-module (`pjsip reload`, `dialplan reload`); the Asterisk
 *    process itself is never restarted.
 */
final class Agent
{
    private const CONTEXT_PATTERN = '/^vodnox_org_[a-z0-9_]{1,32}$/';
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,64}$/';
    private const EXTENSION_PATTERN = '/^[0-9*#]{2,10}$/';

    private string $stateDir;
    private string $generatedDir;
    private string $dialplanDir;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config)
    {
        $this->stateDir = rtrim((string) $config['state_dir'], '/');
        $this->generatedDir = rtrim((string) $config['asterisk']['include_dir'], '/');
        $this->dialplanDir = rtrim((string) $config['asterisk']['dialplan_include_dir'], '/');
    }

    /**
     * @param  array<string, string>  $headers  lower-cased header map
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $method, string $path, array $headers, string $rawBody): array
    {
        try {
            $this->verify($headers, $rawBody);
        } catch (Rejected $e) {
            return ['status' => $e->status, 'body' => ['error' => $e->getMessage()]];
        }

        $key = trim((string) ($headers['idempotency-key'] ?? ''));

        if ($key !== '') {
            return $this->withIdempotency($key, fn () => $this->dispatch($method, $path, $rawBody));
        }

        return $this->dispatch($method, $path, $rawBody);
    }

    // ---------------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, string>  $headers
     */
    private function verify(array $headers, string $rawBody): void
    {
        $expected = 'Bearer '.(string) $this->config['token'];
        if (! hash_equals($expected, (string) ($headers['authorization'] ?? ''))) {
            throw new Rejected('unauthorized', 401);
        }

        $timestamp = (string) ($headers['x-vodnox-timestamp'] ?? '');
        $nonce = (string) ($headers['x-vodnox-nonce'] ?? '');
        $signature = (string) ($headers['x-vodnox-signature'] ?? '');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            throw new Rejected('missing signature headers', 401);
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) $this->config['timestamp_window']) {
            throw new Rejected('stale timestamp', 401);
        }

        $expectedSignature = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$nonce.'.'.$rawBody,
            (string) $this->config['signing_secret'],
        );

        if (! hash_equals($expectedSignature, $signature)) {
            throw new Rejected('bad signature', 401);
        }

        if (! $this->rememberNonce($nonce)) {
            throw new Rejected('replayed request', 401);
        }
    }

    /**
     * Record a nonce once; a second sighting is a replay.
     */
    private function rememberNonce(string $nonce): bool
    {
        $dir = $this->stateDir.'/nonces';
        $this->ensureDir($dir);

        // Cheap opportunistic pruning keeps the directory bounded.
        if (random_int(1, 50) === 1) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < time() - 900) {
                    @unlink($file);
                }
            }
        }

        $handle = @fopen($dir.'/'.hash('sha256', $nonce), 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    /**
     * Collapse retries: the first request with a key writes the response, every
     * later request with the same key replays it without touching Asterisk.
     *
     * @param  callable(): array{status: int, body: array<string, mixed>}  $operation
     * @return array{status: int, body: array<string, mixed>}
     */
    private function withIdempotency(string $key, callable $operation): array
    {
        $dir = $this->stateDir.'/idempotency';
        $this->ensureDir($dir);

        $file = $dir.'/'.hash('sha256', $key);
        $handle = fopen($file, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the idempotency store.');
        }

        try {
            // Serialises concurrent retries of the same operation.
            flock($handle, LOCK_EX);

            $stored = stream_get_contents($handle);
            $decoded = $stored !== false && $stored !== '' ? json_decode($stored, true) : null;

            if (is_array($decoded) && isset($decoded['status'], $decoded['body'])) {
                return ['status' => (int) $decoded['status'], 'body' => (array) $decoded['body']];
            }

            $result = $operation();

            // Only successful reconciliations are memoised so a failed attempt
            // can be retried with the same key.
            if ($result['status'] >= 200 && $result['status'] < 300) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // ---------------------------------------------------------------------
    // Routing
    // ---------------------------------------------------------------------

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function dispatch(string $method, string $path, string $rawBody): array
    {
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === ['health'] && $method === 'GET') {
            return ['status' => 200, 'body' => [
                'ok' => true,
                'service' => 'vodnox-telephony',
                'asterisk' => $this->asteriskVersion(),
            ]];
        }

        $payload = $this->decode($rawBody);

        if ($segments === ['organizations'] && $method === 'POST') {
            return $this->upsertOrganization($payload);
        }

        if (count($segments) === 2 && $segments[0] === 'organizations' && $method === 'DELETE') {
            return $this->removeOrganization(rawurldecode($segments[1]));
        }

        if ($segments === ['extensions'] && $method === 'POST') {
            return $this->upsertExtension($payload);
        }

        if (count($segments) === 2 && $segments[0] === 'extensions' && $method === 'DELETE') {
            return $this->removeExtension(rawurldecode($segments[1]));
        }

        if (in_array($segments[0] ?? '', ['ivr-menus', 'queues'], true)) {
            throw new Rejected('not available in this phase', 501);
        }

        throw new Rejected('unknown endpoint', 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            throw new Rejected('invalid JSON body', 422);
        }

        return $decoded;
    }

    // ---------------------------------------------------------------------
    // Organizations
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function upsertOrganization(array $payload): array
    {
        $context = $this->requireContext($payload['context'] ?? null);
        $organizationId = (int) ($payload['organization_id'] ?? 0);
        $enabled = (bool) ($payload['enabled'] ?? true);

        $organizations = $this->readState('organizations');
        $organizations[$context] = [
            'organization_id' => $organizationId,
            'code' => (string) ($payload['code'] ?? ''),
            'realm' => (string) ($payload['realm'] ?? ''),
            'enabled' => $enabled,
        ];
        $this->writeState('organizations', $organizations);

        $this->writeDialplan($context);
        $this->reload(pjsip: false, dialplan: true);

        return ['status' => 200, 'body' => [
            'ok' => true,
            'context' => $context,
            'enabled' => $enabled,
        ]];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function removeOrganization(string $context): array
    {
        $context = $this->requireContext($context);

        $organizations = $this->readState('organizations');
        unset($organizations[$context]);
        $this->writeState('organizations', $organizations);

        // Every account under the context goes with it; identities are not reused.
        $extensions = $this->readState('extensions');
        foreach ($extensions as $username => $extension) {
            if (($extension['context'] ?? null) === $context) {
                $this->deleteEndpointFile((string) $username);
                unset($extensions[$username]);
            }
        }
        $this->writeState('extensions', $extensions);

        @unlink($this->dialplanFile($context));

        $this->reload(pjsip: true, dialplan: true);

        return ['status' => 200, 'body' => ['ok' => true, 'context' => $context, 'removed' => true]];
    }

    // ---------------------------------------------------------------------
    // Extensions
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function upsertExtension(array $payload): array
    {
        $username = $this->requireUsername($payload['sip_username'] ?? null);
        $context = $this->requireContext($payload['context'] ?? null);
        $extension = (string) ($payload['extension'] ?? '');

        if (! preg_match(self::EXTENSION_PATTERN, $extension)) {
            throw new Rejected('invalid internal number', 422);
        }

        $enabled = (bool) ($payload['enabled'] ?? true);
        $extensions = $this->readState('extensions');
        $password = (string) ($payload['sip_password'] ?? '');

        if ($password === '') {
            // The password is only sent on creation or rotation; on a plain
            // update the previously stored value is reused.
            $password = $this->readPassword($username) ?? '';
        }

        if ($password === '') {
            throw new Rejected('missing SIP password', 422);
        }

        $endpoint = [
            'organization_id' => (int) ($payload['organization_id'] ?? 0),
            'extension_id' => (int) ($payload['extension_id'] ?? 0),
            'context' => $context,
            'extension' => $extension,
            'label' => (string) ($payload['label'] ?? ''),
            'sip_domain' => (string) ($payload['sip_domain'] ?? ''),
            'enabled' => $enabled,
        ];

        if ($enabled) {
            $this->writeEndpointFile($username, $endpoint, $password);
        } else {
            // Disabling removes the account so it can neither register nor be
            // dialled; re-enabling recreates the same single file.
            $this->deleteEndpointFile($username);
        }

        $this->writePassword($username, $password);
        $extensions[$username] = $endpoint;
        $this->writeState('extensions', $extensions);
        $this->writeDialplan($context);
        $this->reload(pjsip: true, dialplan: true);

        return ['status' => 200, 'body' => [
            'ok' => true,
            'sip_username' => $username,
            'enabled' => $enabled,
            'context' => $context,
        ]];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function removeExtension(string $username): array
    {
        $username = $this->requireUsername($username);

        $extensions = $this->readState('extensions');
        $context = (string) ($extensions[$username]['context'] ?? '');

        unset($extensions[$username]);
        $this->writeState('extensions', $extensions);
        $this->deleteEndpointFile($username);
        $this->deletePassword($username);

        if ($context !== '') {
            $this->writeDialplan($context);
        }

        $this->reload(pjsip: true, dialplan: $context !== '');

        return ['status' => 200, 'body' => ['ok' => true, 'sip_username' => $username, 'removed' => true]];
    }

    // ---------------------------------------------------------------------
    // Asterisk configuration
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $endpoint
     */
    private function writeEndpointFile(string $username, array $endpoint, string $password): void
    {
        $this->ensureDir($this->generatedDir);

        $codecs = (string) ($this->config['asterisk']['codecs'] ?? 'opus,ulaw,alaw');
        $transport = (string) ($this->config['asterisk']['transport'] ?? 'transport-wss');
        $label = $endpoint['label'] !== '' ? $endpoint['label'] : $username;

        $config = <<<CONF
        ; VODNOX generated endpoint - do not edit by hand.
        ; organization {$endpoint['organization_id']} / extension {$endpoint['extension']}

        [{$username}]
        type=aor
        max_contacts=1
        remove_existing=yes
        qualify_frequency=30

        [{$username}]
        type=auth
        auth_type=userpass
        username={$username}
        password={$password}

        [{$username}]
        type=endpoint
        transport={$transport}
        context={$endpoint['context']}
        callerid="{$label}" <{$endpoint['extension']}>
        disallow=all
        allow={$codecs}
        aors={$username}
        auth={$username}
        webrtc=yes
        dtls_auto_generate_cert=yes
        media_encryption=dtls
        media_encryption_optimistic=no
        dtls_setup=actpass
        media_use_received_transport=yes
        ice_support=yes
        rtcp_mux=yes
        bundle=yes
        direct_media=no
        force_rport=yes
        rewrite_contact=yes
        rtp_symmetric=yes
        allow_subscribe=no

        CONF;

        $this->atomicWrite($this->endpointFile($username), $config);
    }

    private function deleteEndpointFile(string $username): void
    {
        @unlink($this->endpointFile($username));
    }

    /**
     * Regenerate the whole dialplan for one organization context.
     *
     * Isolation rule: the context contains only the extensions that belong to
     * the organization. There is no include of a shared context and no general
     * catch-all, so a number can never escape to another organization.
     */
    private function writeDialplan(string $context): void
    {
        $this->ensureDir($this->dialplanDir);

        $organizations = $this->readState('organizations');
        $enabled = (bool) ($organizations[$context]['enabled'] ?? true);
        $ringTimeout = (int) ($this->config['asterisk']['ring_timeout'] ?? 30);

        $lines = [
            '; VODNOX generated dialplan - do not edit by hand.',
            '; organization context '.$context,
            '',
            '['.$context.']',
        ];

        if (! $enabled) {
            $lines[] = '; The organization is inactive: no call may leave this context.';
            $lines[] = 'exten => _X.,1,NoOp(VODNOX organization inactive)';
            $lines[] = ' same => n,Hangup(403)';
        } else {
            $extensions = $this->readState('extensions');
            $dialable = array_filter(
                $extensions,
                static fn (array $row): bool => ($row['context'] ?? null) === $context && ($row['enabled'] ?? false),
            );
            ksort($dialable);

            if ($dialable === []) {
                $lines[] = '; No dialable extensions yet.';
            }

            foreach ($dialable as $username => $row) {
                $number = (string) $row['extension'];
                $lines[] = 'exten => '.$number.',1,NoOp(VODNOX internal call to '.$number.')';
                $lines[] = ' same => n,Dial(PJSIP/'.$username.','.$ringTimeout.')';
                $lines[] = ' same => n,Hangup()';
            }

            // Anything that is not one of the numbers above is refused; there is
            // deliberately no fall-through to a shared or public context.
            $lines[] = 'exten => _X.,1,NoOp(VODNOX unknown extension)';
            $lines[] = ' same => n,Hangup(404)';
        }

        $this->atomicWrite($this->dialplanFile($context), implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * Ask Asterisk to re-read only the module that changed. The Asterisk
     * process itself is never restarted.
     */
    private function reload(bool $pjsip, bool $dialplan): void
    {
        $commands = [];

        if ($pjsip) {
            $commands[] = 'pjsip reload';
        }

        if ($dialplan) {
            $commands[] = 'dialplan reload';
        }

        $binary = (string) ($this->config['asterisk']['binary'] ?? '/usr/sbin/asterisk');

        foreach ($commands as $command) {
            $output = [];
            $status = 0;
            exec(escapeshellarg($binary).' -rx '.escapeshellarg($command).' 2>&1', $output, $status);

            if ($status !== 0) {
                throw new RuntimeException($command.' failed: '.implode(' ', $output));
            }
        }
    }

    private function asteriskVersion(): ?string
    {
        $binary = (string) ($this->config['asterisk']['binary'] ?? '/usr/sbin/asterisk');
        $output = [];
        $status = 0;

        exec(escapeshellarg($binary).' -rx '.escapeshellarg('core show version').' 2>&1', $output, $status);

        return $status === 0 ? trim(implode(' ', $output)) : null;
    }

    // ---------------------------------------------------------------------
    // State, files and validation
    // ---------------------------------------------------------------------

    private function endpointFile(string $username): string
    {
        return $this->generatedDir.'/extension_'.$username.'.conf';
    }

    private function dialplanFile(string $context): string
    {
        return $this->dialplanDir.'/dialplan_'.$context.'.conf';
    }

    private function passwordFile(string $username): string
    {
        return $this->stateDir.'/secrets/'.$username;
    }

    private function writePassword(string $username, string $password): void
    {
        $this->ensureDir($this->stateDir.'/secrets');
        $this->atomicWrite($this->passwordFile($username), $password, 0600);
    }

    private function readPassword(string $username): ?string
    {
        $file = $this->passwordFile($username);

        if (! is_file($file)) {
            return null;
        }

        $value = trim((string) file_get_contents($file));

        return $value !== '' ? $value : null;
    }

    private function deletePassword(string $username): void
    {
        @unlink($this->passwordFile($username));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readState(string $name): array
    {
        $file = $this->stateDir.'/'.$name.'.json';

        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function writeState(string $name, array $value): void
    {
        $this->ensureDir($this->stateDir);
        $this->atomicWrite(
            $this->stateDir.'/'.$name.'.json',
            (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function atomicWrite(string $path, string $content, int $mode = 0640): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write '.$path);
        }

        @chmod($temporary, $mode);

        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace '.$path);
        }
    }

    private function ensureDir(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create '.$path);
        }
    }

    private function requireContext(mixed $value): string
    {
        $context = is_string($value) ? $value : '';

        if (! preg_match(self::CONTEXT_PATTERN, $context)) {
            throw new Rejected('invalid organization context', 422);
        }

        return $context;
    }

    private function requireUsername(mixed $value): string
    {
        $username = is_string($value) ? $value : '';

        if (! preg_match(self::USERNAME_PATTERN, $username)) {
            throw new Rejected('invalid SIP username', 422);
        }

        return $username;
    }
}
