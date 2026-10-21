<?php

declare(strict_types=1);

namespace Vodnox\Telephony;

use RuntimeException;
use Throwable;

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
 *   1. the generated Asterisk configuration for each organization/extension and
 *      for each GSM gateway line;
 *   2. the non-disruptive reload commands for that configuration.
 *
 * Security model
 * --------------
 *  - Authenticated by a bearer token AND an HMAC-SHA256 signature over the
 *    exact request bytes, with a bounded timestamp window, a single-use nonce
 *    (replay protection) and a stable Idempotency-Key (retry collapse).
 *  - It never talks to AMI/ARI, never exposes a shell, never accepts a path
 *    from the caller: file names are derived from a validated SIP username.
 *  - Ownership is read from the agent's own state, never from the request: a
 *    gateway line can only be updated, removed or inspected by the
 *    organization that provisioned it.
 *  - Writes are atomic (temp file + rename) so a crash can never leave a
 *    half-written configuration behind.
 *  - Reloads are per-module (`pjsip reload`, `dialplan reload`); the Asterisk
 *    process itself is never restarted. The UDB transport is never created or
 *    restarted here — it is a one-time server prerequisite.
 */
final class Agent
{
    /**
     * Bumped whenever the API surface changes, so Laravel can tell which agent
     * answer it is reading.
     */
    public const VERSION = '1.3.0';

    /**
     * Operations this build actually implements. Laravel refuses to show a
     * gateway line as "synced/registered" when the answering agent does not
     * advertise `gateway_lines` (an older agent answers 404 or 501).
     *
     * `gateway_routing` means the agent compiles the *whole* rule set of a line
     * (temporary forward, dated exceptions, weekend, business hours, outside
     * hours, default) into the dialplan and therefore decides at call time.
     * Without it the line can only dial one fixed destination, so the routing
     * page asks for a service update instead of pretending the schedule is live.
     */
    public const CAPABILITIES = ['organizations', 'extensions', 'gateway_lines', 'gateway_routing', 'gateway_status'];

    private const CONTEXT_PATTERN = '/^vodnox_(?:org|gw)_[a-z0-9_]{1,48}$/';
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_.-]{3,64}$/';
    private const EXTENSION_PATTERN = '/^[0-9*#]{2,10}$/';
    private const TIMEZONE_PATTERN = '/^[A-Za-z0-9_+\-]{1,32}(?:\/[A-Za-z0-9_+\-]{1,32}){0,2}$/';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    private const TIME_OF_DAY_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    /** Upper bound on a single status request, so one call cannot fan out. */
    private const MAX_STATUS_LINES = 50;

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
                'agent_version' => self::VERSION,
                'capabilities' => self::CAPABILITIES,
                'asterisk' => $this->asteriskVersion(),
            ]];
        }

        if ($segments === ['capabilities'] && $method === 'GET') {
            return ['status' => 200, 'body' => [
                'ok' => true,
                'agent_version' => self::VERSION,
                'capabilities' => self::CAPABILITIES,
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

        if ($segments === ['gateway-lines'] && $method === 'POST') {
            return $this->upsertGatewayLine($payload);
        }

        if (count($segments) === 2 && $segments[0] === 'gateway-lines' && $method === 'DELETE') {
            return $this->removeGatewayLine(rawurldecode($segments[1]));
        }

        if ($segments === ['gateway-status'] && $method === 'POST') {
            return $this->gatewayStatus($payload);
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
        $this->refreshGatewayDialplans($organizationId);
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
        $organizationId = (int) ($organizations[$context]['organization_id'] ?? 0);
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

        // A GSM gateway line belongs to the organization too: leaving its trunk
        // live after the organization is gone would be a silent leak.
        $gateways = $this->readState('gateways');
        foreach ($gateways as $username => $gateway) {
            if ((int) ($gateway['organization_id'] ?? 0) === $organizationId || ($gateway['organization_context'] ?? null) === $context) {
                $this->deleteGatewayEndpointFile((string) $username);
                $this->deletePassword((string) $username);
                @unlink($this->gatewayDialplanFile((string) ($gateway['context'] ?? '')));
                unset($gateways[$username]);
            }
        }
        $this->writeState('gateways', $gateways);

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
    // GSM gateway lines
    // ---------------------------------------------------------------------

    /**
     * Create or reconcile one gateway line.
     *
     * The line gets its own SIP account, its own dedicated inbound context and
     * its own routing rule set; there is deliberately no way to point two SIMs
     * (or two organizations) at the same endpoint.
     *
     * The `routing` block is the authoritative "where does a call arriving on
     * this SIM go" definition; it is validated here and compiled into the
     * dialplan so the decision happens at call time, with no browser and no
     * scheduler involved. A line that carries no routing block at all (an
     * older caller) keeps the legacy single-destination behaviour.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function upsertGatewayLine(array $payload): array
    {
        $username = $this->requireUsername($payload['sip_username'] ?? null);
        $context = $this->requireContext($payload['context'] ?? null);
        $organizationContext = $this->requireContext($payload['organization_context'] ?? null);
        $organizationId = (int) ($payload['organization_id'] ?? 0);

        $inboundNumber = (string) ($payload['inbound_number'] ?? '');
        if (! preg_match(self::EXTENSION_PATTERN, $inboundNumber)) {
            throw new Rejected('invalid inbound number', 422);
        }

        // A port may legitimately have no destination yet: it still needs its
        // SIP account so the device can register and be observed, and the
        // dialplan then refuses the call with a readable reason.
        $destination = $this->optionalUsername($payload['destination_username'] ?? null);
        $routing = $this->normalizeRouting($payload['routing'] ?? null);
        $enabled = (bool) ($payload['enabled'] ?? true);

        $gateways = $this->readState('gateways');
        $existing = $gateways[$username] ?? null;

        // Ownership: an existing endpoint may never be re-pointed at another
        // organization, whatever the request claims.
        if (is_array($existing) && (int) ($existing['organization_id'] ?? 0) !== $organizationId) {
            throw new Rejected('gateway line belongs to another organization', 403);
        }

        $password = (string) ($payload['sip_password'] ?? '');
        if ($password === '') {
            $password = $this->readPassword($username) ?? '';
        }

        if ($password === '') {
            throw new Rejected('missing SIP password', 422);
        }

        $record = [
            'organization_id' => $organizationId,
            'gateway_id' => (int) ($payload['gateway_id'] ?? 0),
            'line_id' => (int) ($payload['line_id'] ?? 0),
            'line_index' => (int) ($payload['line_index'] ?? 1),
            'context' => $context,
            'organization_context' => $organizationContext,
            'inbound_number' => $inboundNumber,
            'destination_username' => $destination,
            'routing' => $routing,
            'label' => (string) ($payload['label'] ?? ''),
            'enabled' => $enabled,
        ];

        if ($enabled) {
            $this->writeGatewayEndpointFile($username, $record, $password);
        } else {
            $this->deleteGatewayEndpointFile($username);
        }

        $this->writePassword($username, $password);
        $gateways[$username] = $record;
        $this->writeState('gateways', $gateways);
        $this->writeGatewayDialplan($context);
        $this->reload(pjsip: true, dialplan: true);

        return ['status' => 200, 'body' => [
            'ok' => true,
            'sip_username' => $username,
            'context' => $context,
            'inbound_number' => $inboundNumber,
            'routing_rules' => $routing === null ? 0 : count($routing['exceptions']) + count($routing['business_hours']) + count($routing['weekend_days']),
            'enabled' => $enabled,
        ]];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function removeGatewayLine(string $username): array
    {
        $username = $this->requireUsername($username);

        $gateways = $this->readState('gateways');
        $record = $gateways[$username] ?? null;

        if (! is_array($record)) {
            return ['status' => 200, 'body' => ['ok' => true, 'sip_username' => $username, 'removed' => true]];
        }

        $context = (string) ($record['context'] ?? '');

        unset($gateways[$username]);
        $this->writeState('gateways', $gateways);
        $this->deleteGatewayEndpointFile($username);
        $this->deletePassword($username);

        if ($context !== '') {
            @unlink($this->gatewayDialplanFile($context));
        }

        $this->reload(pjsip: true, dialplan: $context !== '');

        return ['status' => 200, 'body' => ['ok' => true, 'sip_username' => $username, 'removed' => true]];
    }

    /**
     * A real registration snapshot read from Asterisk.
     *
     * Only lines owned by the requesting organization are answered; anything
     * else is refused instead of being reported, so a status call can never
     * enumerate another tenant's endpoints.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function gatewayStatus(array $payload): array
    {
        $organizationId = (int) ($payload['organization_id'] ?? 0);
        $requested = $payload['sip_usernames'] ?? null;

        if (! is_array($requested) || $requested === []) {
            throw new Rejected('no gateway lines requested', 422);
        }

        if (count($requested) > self::MAX_STATUS_LINES) {
            throw new Rejected('too many gateway lines requested', 422);
        }

        $gateways = $this->readState('gateways');
        $contacts = $this->contacts();

        $lines = [];

        foreach ($requested as $raw) {
            $username = $this->requireUsername(is_string($raw) ? $raw : null);
            $owned = $gateways[$username] ?? null;

            if (! is_array($owned) || (int) ($owned['organization_id'] ?? 0) !== $organizationId) {
                throw new Rejected('gateway line is not owned by this organization', 403);
            }

            $lines[$username] = $this->contactState($username, $contacts);
        }

        return ['status' => 200, 'body' => [
            'ok' => true,
            'source' => $contacts === null ? 'unavailable' : 'asterisk',
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'lines' => $lines,
        ]];
    }

    /**
     * @param  array<string, array{status: string, reachable: bool}>|null  $contacts
     * @return array<string, mixed>
     */
    private function contactState(string $username, ?array $contacts): array
    {
        if ($contacts === null) {
            // No monitoring source: say so, rather than guess "disconnected".
            return ['state' => 'unknown', 'registered' => false, 'reachable' => false, 'reason' => 'monitoring_unavailable'];
        }

        $contact = $contacts[$username] ?? null;

        if ($contact === null) {
            return ['state' => 'no_contact', 'registered' => false, 'reachable' => false, 'reason' => 'no_contact'];
        }

        return [
            'state' => $contact['reachable'] ? 'registered' : 'unreachable',
            'registered' => true,
            'reachable' => $contact['reachable'],
            'status' => $contact['status'],
        ];
    }

    /**
     * Parse `pjsip show contacts` into a username map, or null when the CLI is
     * unavailable/timed out. Never throws: an unreachable monitoring source is
     * an "unknown" answer, not a failed provisioning call.
     *
     * @return array<string, array{status: string, reachable: bool}>|null
     */
    private function contacts(): ?array
    {
        try {
            $output = $this->runCli('pjsip show contacts', (int) ($this->config['asterisk']['cli_timeout'] ?? 5));
        } catch (Throwable) {
            return null;
        }

        $contacts = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (! preg_match('/([A-Za-z0-9_.-]{3,64})\/(?:sips?):/', $line, $match)) {
                continue;
            }

            $status = 'unknown';

            if (preg_match('/\b(Avail|Unavail|NonQualified|Unknown|Removed)\b/', $line, $found)) {
                $status = strtolower($found[1]);
            }

            $contacts[$match[1]] = [
                'status' => $status,
                'reachable' => in_array($status, ['avail', 'nonqualified', 'unknown'], true),
            ];
        }

        return $contacts;
    }

    /**
     * Run a fixed, allow-listed Asterisk CLI command with a hard timeout. The
     * command is never taken from the request.
     */
    private function runCli(string $command, int $timeoutSeconds): string
    {
        if (! in_array($command, ['pjsip show contacts', 'core show version', 'pjsip reload', 'dialplan reload'], true)) {
            throw new RuntimeException('refused unlisted CLI command');
        }

        $binary = (string) ($this->config['asterisk']['binary'] ?? '/usr/sbin/asterisk');
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = @proc_open([$binary, '-rx', $command], $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('unable to run asterisk');
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $output = '';
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        $status = ['running' => true, 'exitcode' => 0];

        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new RuntimeException($command.' timed out after '.$timeoutSeconds.'s');
            }

            usleep(50000);
        }

        $output .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ((int) ($status['exitcode'] ?? 0) !== 0) {
            throw new RuntimeException($command.' failed: '.trim($output));
        }

        return $output;
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
     * The classic SIP/UDP endpoint a GSM gateway registers to.
     *
     * Deliberately *not* a WebRTC account: no DTLS, no ICE, no bundle. The
     * transport is the one already configured on the server
     * (`asterisk.gateway_transport`), never created here.
     *
     * @param  array<string, mixed>  $record
     */
    private function writeGatewayEndpointFile(string $username, array $record, string $password): void
    {
        $this->ensureDir($this->generatedDir);

        $transport = (string) ($this->config['asterisk']['gateway_transport'] ?? 'transport-udp');
        $codecs = (string) ($this->config['asterisk']['gateway_codecs'] ?? 'ulaw,alaw');
        $label = $record['label'] !== '' ? $record['label'] : $username;
        $inbound = (string) $record['inbound_number'];

        $config = <<<CONF
        ; VODNOX generated GSM gateway endpoint - do not edit by hand.
        ; organization {$record['organization_id']} / gateway {$record['gateway_id']} / line {$record['line_id']}
        ; Classic SIP over UDP: no WebRTC, no DTLS, no ICE.

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
        context={$record['context']}
        callerid="{$label}" <{$inbound}>
        disallow=all
        allow={$codecs}
        aors={$username}
        auth={$username}
        direct_media=no
        rtp_symmetric=yes
        force_rport=yes
        rewrite_contact=yes
        dtmf_mode=rfc4733
        allow_subscribe=no
        allow_transfer=no

        CONF;

        $this->atomicWrite($this->gatewayEndpointFile($username), $config);
    }

    private function deleteGatewayEndpointFile(string $username): void
    {
        @unlink($this->gatewayEndpointFile($username));
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
     * The inbound context for one gateway line.
     *
     * It accepts exactly one number — the generated inbound path of that line —
     * and the *whole* routing rule set of the line is compiled into the dialplan,
     * so the destination is decided at call time. There is no catch-all that
     * forwards somewhere else, so a call arriving on SIM A can never reach the
     * destination configured for SIM B, let alone another organization.
     *
     * Priority is fixed and identical to the administrator's preview:
     *   1. the temporary permanent forward, while it is switched on;
     *   2. a dated exception whose inclusive range covers today;
     *   3. the weekly rules — a weekend day, then the business-hours windows;
     *   4. the default destination.
     * A rule that resolves to no usable target is refused with 480; nothing is
     * guessed and the call never leaves the organization.
     *
     * Note: the payload already applied the configured fallback to each rule,
     * so a rule with an empty username means "neither the rule nor the fallback
     * is usable" and is refused.
     */
    private function writeGatewayDialplan(string $context): void
    {
        $this->ensureDir($this->dialplanDir);

        $gateways = $this->readState('gateways');
        $record = null;

        foreach ($gateways as $row) {
            if (($row['context'] ?? null) === $context) {
                $record = $row;
                break;
            }
        }

        if ($record === null) {
            @unlink($this->gatewayDialplanFile($context));

            return;
        }

        $organizations = $this->readState('organizations');
        $organizationContext = (string) ($record['organization_context'] ?? '');
        $enabled = (bool) ($record['enabled'] ?? false)
            && (bool) ($organizations[$organizationContext]['enabled'] ?? true);

        $ringTimeout = (int) ($this->config['asterisk']['ring_timeout'] ?? 30);

        $lines = [
            '; VODNOX generated GSM gateway inbound dialplan - do not edit by hand.',
            '; organization '.$record['organization_id'].' / gateway '.$record['gateway_id'].' / line '.$record['line_id'],
            '; Routing priority: temporary forward, dated exception, weekend, business',
            '; hours / outside hours, default. An unresolved rule is refused (480);',
            '; the call never falls through to another organization or an outbound path.',
            '',
            '['.$context.']',
        ];

        if (! $enabled) {
            $lines[] = '; The line or its organization is inactive: refuse new calls.';
            $lines[] = 'exten => _X.,1,NoOp(VODNOX gateway line inactive)';
            $lines[] = ' same => n,Hangup(403)';

            $this->atomicWrite($this->gatewayDialplanFile($context), implode(PHP_EOL, $lines).PHP_EOL);

            return;
        }

        $number = (string) $record['inbound_number'];
        $routing = is_array($record['routing'] ?? null) ? $record['routing'] : null;

        $lines[] = 'exten => '.$number.',1,NoOp(VODNOX GSM inbound on '.$number.')';
        $lines[] = ' same => n,Set(VDX_TARGET=)';

        if ($routing === null) {
            // Legacy payload: one fixed destination, no schedule.
            $legacy = $this->optionalUsername($record['destination_username'] ?? null);

            if ($legacy === null) {
                $lines[] = ' same => n,NoOp(VODNOX no destination configured)';
                $lines[] = ' same => n,Hangup(480)';
            } else {
                $lines[] = ' same => n,Set(VDX_TARGET='.$legacy.')';
                $lines[] = ' same => n,Dial(PJSIP/${VDX_TARGET},'.$ringTimeout.')';
                $lines[] = ' same => n,Hangup()';
            }

            $lines[] = '; No fall-through: any other number arriving on this trunk is dropped.';
            $lines[] = 'exten => _X.,1,NoOp(VODNOX gateway unknown number)';
            $lines[] = ' same => n,Hangup(404)';

            $this->atomicWrite($this->gatewayDialplanFile($context), implode(PHP_EOL, $lines).PHP_EOL);

            return;
        }

        foreach ($this->routingDialplan($routing, $ringTimeout) as $line) {
            $lines[] = $line;
        }

        // No fall-through: any other number arriving on this trunk is dropped.
        $lines[] = 'exten => _X.,1,NoOp(VODNOX gateway unknown number)';
        $lines[] = ' same => n,Hangup(404)';

        $this->atomicWrite($this->gatewayDialplanFile($context), implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * Compile the routing rule set of one line into dialplan statements.
     *
     * The clock is sampled *once* per call and then converted to the
     * organization's timezone *explicitly* for every lookup: `${STRFTIME}` reads
     * its zone from the second argument, so passing the organization zone is
     * what makes the call agree with the administrator's preview. An empty
     * second argument would silently fall back to the server's zone, and
     * sampling `${EPOCH}` once removes the risk of the weekday, the time and the
     * date being read on opposite sides of a second or midnight boundary.
     * All comparisons are string comparisons on `%Y%m%d` / `%H%M`, which keeps
     * the dialplan readable and removes any dependency on Asterisk's
     * day-of-week/time-range helpers. `TZ` is still set on the channel so the
     * rest of the channel's own time functions follow the organization too.
     *
     * @param  array<string, mixed>  $routing  already validated by normalizeRouting()
     * @return array<int, string>
     */
    private function routingDialplan(array $routing, int $ringTimeout): array
    {
        /** @var array<string, mixed> $routing */
        $timezone = (string) $routing['timezone'];

        $lines = [
            '; The clock is sampled once and converted with the organization timezone',
            '; explicitly: an empty STRFTIME zone argument would use the server zone.',
            ' same => n,Set(TZ='.$timezone.')',
            ' same => n,Set(VDX_TZ='.$timezone.')',
            ' same => n,Set(VDX_NOW=${EPOCH})',
            ' same => n,Set(VDX_W=${STRFTIME(${VDX_NOW},${VDX_TZ},%w)})',
            ' same => n,Set(VDX_T=${STRFTIME(${VDX_NOW},${VDX_TZ},%H%M)})',
            ' same => n,Set(VDX_D=${STRFTIME(${VDX_NOW},${VDX_TZ},%Y%m%d)})',
        ];

        $branches = [];

        if ($routing['temporary_forward_enabled']) {
            // Priority 1: while the temporary forward is on it wins outright, so
            // the remaining rules are deliberately not compiled in. A forward
            // that resolves to no usable target is still compiled — and then
            // refused — so the call can never fall through to a lower rule.
            $lines[] = '; 1. Temporary permanent forward is ON: it wins over every other rule.';
            $lines[] = ' same => n,Goto(vdx_temporary)';
            $branches[] = ['label' => 'vdx_temporary', 'target' => $routing['temporary_forward_username'] ?? '', 'note' => '1. temporary forward'];
        } else {
            foreach ($routing['exceptions'] as $index => $exception) {
                $start = str_replace('-', '', (string) $exception['starts_on']);
                $end = str_replace('-', '', (string) $exception['ends_on']);
                $label = 'vdx_exception_'.$index;

                $lines[] = '; 2. Dated exception '.$exception['starts_on'].'..'.$exception['ends_on'].' (both days inclusive).';
                $lines[] = ' same => n,GotoIf($[${VDX_D} >= '.$start.' & ${VDX_D} <= '.$end.']?'.$label.')';
                // The target may legitimately be empty: the exception matches,
                // but neither it nor the fallback has a usable destination, so
                // the call is refused instead of dropping to a weekly rule.
                $branches[] = ['label' => $label, 'target' => $exception['destination_username'] ?? '', 'note' => '2. dated exception'];
            }

            $weekendDays = $routing['weekend_days'];

            if ($weekendDays !== []) {
                $condition = implode(' | ', array_map(
                    static fn (int $day): string => '${VDX_W} == '.$day,
                    $weekendDays,
                ));

                if ($routing['weekend_username'] === null) {
                    // A weekend day with no destination of its own and no
                    // after-hours destination to reuse: refuse rather than
                    // falling through to the default.
                    $lines[] = '; 3. Weekly holiday with no destination: refuse.';
                    $lines[] = ' same => n,GotoIf($['.$condition.']?vdx_refuse)';
                } else {
                    $lines[] = '; 3. Weekly holiday (days '.implode(',', $weekendDays).').';
                    $lines[] = ' same => n,GotoIf($['.$condition.']?vdx_weekend)';
                    $branches[] = ['label' => 'vdx_weekend', 'target' => $routing['weekend_username'], 'note' => '3. weekly holiday'];
                }
            }

            if ($routing['business_hours'] !== []) {
                $clauses = [];

                foreach ($routing['business_hours'] as $window) {
                    $day = (int) $window['day'];
                    $start = str_replace(':', '', (string) $window['start']);
                    $end = str_replace(':', '', (string) $window['end']);

                    // End of a window is exclusive; end <= start crosses midnight.
                    if ($end > $start) {
                        $clauses[] = '(${VDX_W} == '.$day.' & ${VDX_T} >= '.$start.' & ${VDX_T} < '.$end.')';
                    } else {
                        $nextDay = ($day + 1) % 7;
                        $clauses[] = '((${VDX_W} == '.$day.' & ${VDX_T} >= '.$start.') | (${VDX_W} == '.$nextDay.' & ${VDX_T} < '.$end.'))';
                    }
                }

                $lines[] = '; 3. Business hours (a window whose end is not after its start crosses midnight).';
                $lines[] = ' same => n,GotoIf($['.implode(' | ', $clauses).']?vdx_in_hours)';
                $lines[] = ' same => n,Goto(vdx_outside)';
                $branches[] = ['label' => 'vdx_in_hours', 'target' => $routing['in_hours_username'], 'note' => 'business hours'];
                $branches[] = ['label' => 'vdx_outside', 'target' => $routing['outside_hours_username'], 'note' => 'outside hours'];
            }

            $lines[] = '; 4. No rule matched: the default destination.';
            $lines[] = ' same => n,Goto(vdx_default)';
            $branches[] = ['label' => 'vdx_default', 'target' => $routing['default_username'], 'note' => '4. default'];
        }

        $lines[] = '';

        foreach ($branches as $branch) {
            $lines[] = '; '.$branch['note'];
            $lines[] = ' same => n('.$branch['label'].'),Set(VDX_TARGET='.(string) $branch['target'].')';
            $lines[] = ' same => n,Goto(vdx_dial)';
        }

        $lines[] = '';
        $lines[] = ' same => n(vdx_dial),GotoIf($["${VDX_TARGET}" = ""]?vdx_refuse)';
        $lines[] = ' same => n,Dial(PJSIP/${VDX_TARGET},'.$ringTimeout.')';
        $lines[] = ' same => n,Hangup()';
        $lines[] = ' same => n(vdx_refuse),NoOp(VODNOX no usable destination for this line)';
        $lines[] = ' same => n,Hangup(480)';

        return $lines;
    }

    /**
     * Re-generate every gateway dialplan of one organization (used when the
     * organization is enabled/disabled so its trunks follow).
     */
    private function refreshGatewayDialplans(int $organizationId): void
    {
        if ($organizationId <= 0) {
            return;
        }

        foreach ($this->readState('gateways') as $row) {
            if ((int) ($row['organization_id'] ?? 0) === $organizationId && ($row['context'] ?? '') !== '') {
                $this->writeGatewayDialplan((string) $row['context']);
            }
        }
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

    private function gatewayEndpointFile(string $username): string
    {
        return $this->generatedDir.'/gateway_'.$username.'.conf';
    }

    private function dialplanFile(string $context): string
    {
        return $this->dialplanDir.'/dialplan_'.$context.'.conf';
    }

    private function gatewayDialplanFile(string $context): string
    {
        return $this->dialplanDir.'/gateway_'.$context.'.conf';
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

    /**
     * A destination that is allowed to be absent.
     *
     * A port without a destination is a normal state (its SIM is not used yet),
     * so an empty value is returned as null instead of being refused.
     */
    private function optionalUsername(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requireUsername($value);
    }

    /**
     * Validate the routing block of a line and reduce it to the exact values the
     * dialplan needs.
     *
     * Anything malformed is refused (422) rather than written into a generated
     * configuration: a bad rule must fail the provisioning call, not silently
     * produce a dialplan that sends a call somewhere unintended.
     *
     * Returns null when the payload carries no routing block at all, which keeps
     * the legacy single-destination behaviour for an older caller.
     *
     * @return array{
     *     timezone: string,
     *     temporary_forward_enabled: bool,
     *     temporary_forward_username: ?string,
     *     exceptions: array<int, array{starts_on: string, ends_on: string, destination_username: ?string}>,
     *     weekend_days: array<int, int>,
     *     business_hours: array<int, array{day: int, start: string, end: string}>,
     *     in_hours_username: ?string,
     *     outside_hours_username: ?string,
     *     weekend_username: ?string,
     *     default_username: ?string
     * }|null
     */
    private function normalizeRouting(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $timezone = (string) ($value['timezone'] ?? '');

        if (! preg_match(self::TIMEZONE_PATTERN, $timezone)) {
            throw new Rejected('invalid routing timezone', 422);
        }

        $exceptions = [];

        foreach ((array) ($value['exceptions'] ?? []) as $exception) {
            if (! is_array($exception)) {
                throw new Rejected('invalid routing exception', 422);
            }

            $starts = (string) ($exception['starts_on'] ?? '');
            $ends = (string) ($exception['ends_on'] ?? '');
            $username = $this->optionalUsername($exception['destination_username'] ?? null);

            // `ends_on` is inclusive, so a range is valid as long as it is not
            // inverted. A missing username is NOT an error: the exception still
            // matches on its dates and is then refused, so a call can never
            // silently drop to a lower-priority rule.
            if (
                ! preg_match(self::DATE_PATTERN, $starts)
                || ! preg_match(self::DATE_PATTERN, $ends)
                || $ends < $starts
            ) {
                throw new Rejected('invalid routing exception', 422);
            }

            $exceptions[] = ['starts_on' => $starts, 'ends_on' => $ends, 'destination_username' => $username];
        }

        if (count($exceptions) > 50) {
            throw new Rejected('too many routing exceptions', 422);
        }

        $weekendDays = [];

        foreach ((array) ($value['weekend_days'] ?? []) as $day) {
            $day = (int) $day;

            if ($day < 0 || $day > 6) {
                throw new Rejected('invalid weekly holiday day', 422);
            }

            $weekendDays[] = $day;
        }

        $weekendDays = array_values(array_unique($weekendDays));
        sort($weekendDays);

        $windows = [];

        foreach ((array) ($value['business_hours'] ?? []) as $window) {
            if (! is_array($window)) {
                throw new Rejected('invalid business hours window', 422);
            }

            $day = (int) ($window['day'] ?? -1);
            $start = (string) ($window['start'] ?? '');
            $end = (string) ($window['end'] ?? '');

            if (
                $day < 0 || $day > 6
                || ! preg_match(self::TIME_OF_DAY_PATTERN, $start)
                || ! preg_match(self::TIME_OF_DAY_PATTERN, $end)
                || $start === $end
            ) {
                throw new Rejected('invalid business hours window', 422);
            }

            $windows[] = ['day' => $day, 'start' => $start, 'end' => $end];
        }

        if (count($windows) > 50) {
            throw new Rejected('too many business hours windows', 422);
        }

        // `null` means the forward is off; an object means it is ON, even when
        // its destination is empty — then the forward still matches and the call
        // is refused rather than falling through to a lower-priority rule.
        $temporaryForward = $value['temporary_forward'] ?? null;
        $temporaryEnabled = is_array($temporaryForward);
        $temporary = $temporaryEnabled ? ($temporaryForward['destination_username'] ?? null) : null;

        return [
            'timezone' => $timezone,
            'temporary_forward_enabled' => $temporaryEnabled,
            'temporary_forward_username' => $this->optionalUsername($temporary),
            'exceptions' => $exceptions,
            'weekend_days' => $weekendDays,
            'business_hours' => $windows,
            // Each of these already carries the configured fallback when the
            // rule's own destination was unusable (resolved by the application),
            // so an empty value genuinely means "refuse".
            'in_hours_username' => $this->optionalUsername($value['in_hours_destination_username'] ?? null),
            'outside_hours_username' => $this->optionalUsername($value['outside_hours_destination_username'] ?? null),
            'weekend_username' => $this->optionalUsername($value['weekend_destination_username'] ?? null),
            'default_username' => $this->optionalUsername($value['default_destination_username'] ?? null),
        ];
    }
}
