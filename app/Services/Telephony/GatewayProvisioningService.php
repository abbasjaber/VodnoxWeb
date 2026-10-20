<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\TelephonyGateway;
use App\Jobs\ProvisionTelephonyJob;
use App\Models\Extension;
use App\Models\GatewayDevice;
use App\Models\GatewayLine;
use App\Models\Organization;

/**
 * Orchestrates GSM gateway provisioning between VODNOX and the Asterisk agent.
 *
 * Design rules enforced here:
 *  - every line gets its own SIP account, its own inbound context and exactly
 *    one inbound destination, so two SIMs can never share a route;
 *  - the inbound number and the SIP identity are allocated *server side* — an
 *    administrator never types dialplan text or a SIP username;
 *  - the inbound destination is stored as a reference to an extension of the
 *    same organization, never as a copied string;
 *  - secrets never enter a job payload: the job carries identifiers and the
 *    revision only and reads the (encrypted) password at run time;
 *  - authentication/registration/test are three *independent* states. A missing
 *    monitoring reading is reported as unknown, never as "disconnected".
 */
class GatewayProvisioningService
{
    /** @var array{agent_version: ?string, capabilities: array<int, string>, simulated?: bool}|null */
    private ?array $capabilities = null;

    public function __construct(
        private readonly GatewayCredentialService $credentials,
        private readonly TelephonyProvisioningService $provisioning,
        private readonly TelephonyGateway $gateway,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Capability / version negotiation
    |--------------------------------------------------------------------------
    */

    /**
     * What the answering agent can actually do (cached per request).
     *
     * @return array{agent_version: ?string, capabilities: array<int, string>, simulated?: bool}
     */
    public function capabilities(bool $refresh = false): array
    {
        if ($this->capabilities === null || $refresh) {
            $this->capabilities = $this->gateway->capabilities();
        }

        return $this->capabilities;
    }

    /**
     * Whether the agent implements gateway-line provisioning. An older agent
     * omits the capability, and the UI then asks for a service update instead
     * of showing a fake "synced".
     */
    public function agentSupportsGateways(bool $refresh = false): bool
    {
        return in_array('gateway_lines', $this->capabilities($refresh)['capabilities'], true);
    }

    public function isSimulated(): bool
    {
        return $this->gateway->isSimulated();
    }

    /**
     * The classic SIP/UDP settings an installer types into the device.
     *
     * @return array{host: string, port: int, transport: string, domain: string, register_interval: int}
     */
    public function serverSettings(): array
    {
        return [
            'host' => (string) config('integrations.telephony.gateway_sip_host', ''),
            'port' => (int) config('integrations.telephony.gateway_sip_port', 5060),
            'transport' => (string) config('integrations.telephony.gateway_sip_transport', 'udp'),
            'domain' => (string) config('integrations.telephony.gateway_realm', ''),
            'register_interval' => (int) config('integrations.telephony.gateway_registration_interval', 60),
        ];
    }

    /**
     * The device profiles that were actually exercised.
     *
     * @return array<string, array{label_ar: string, label_en: string, lines: int, verified: bool}>
     */
    public function profiles(): array
    {
        return (array) config('integrations.telephony.gateway_profiles', []);
    }

    /*
    |--------------------------------------------------------------------------
    | Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Create a gateway device together with its lines.
     *
     * @param  array<string, mixed>  $attributes  label, model, firmware, network_notes, enabled
     * @param  array<int, array<string, mixed>>  $lines  label, sim_number, inbound_extension_id, enabled
     */
    public function createDevice(Organization $organization, ?int $createdBy, array $attributes, array $lines): GatewayDevice
    {
        $profile = $this->profile((string) ($attributes['model'] ?? ''));

        $device = GatewayDevice::create([
            'organization_id' => $organization->getKey(),
            'created_by' => $createdBy,
            'label' => (string) $attributes['label'],
            'model' => $profile['key'],
            'max_lines' => $profile['lines'],
            'firmware' => $attributes['firmware'] ?? null,
            'network_notes' => $attributes['network_notes'] ?? null,
            'enabled' => (bool) ($attributes['enabled'] ?? true),
            'provisioning_status' => 'pending',
        ]);

        foreach (array_slice(array_values($lines), 0, $profile['lines']) as $index => $row) {
            // The SIP identity and the inbound path are allocated *before* the
            // row is inserted: both columns are NOT NULL and unique, and the
            // administrator never supplies either of them.
            $line = new GatewayLine([
                'organization_id' => $organization->getKey(),
                'line_index' => $index + 1,
                'label' => $row['label'] ?? null,
                'sim_number' => $row['sim_number'] ?? null,
                'inbound_extension_id' => $row['inbound_extension_id'] ?? null,
                'enabled' => (bool) ($row['enabled'] ?? true),
                'provisioning_status' => 'pending',
            ]);

            $line->fill($this->credentials->attributes($line));
            $line->inbound_number = $this->allocateInboundNumber($organization);

            $device->lines()->save($line);

            $this->syncLine($line);
        }

        return $device->refresh();
    }

    /**
     * @return array{key: string, lines: int, verified: bool}
     */
    public function profile(string $key): array
    {
        $profiles = $this->profiles();

        if (isset($profiles[$key])) {
            return ['key' => $key] + [
                'lines' => (int) ($profiles[$key]['lines'] ?? 1),
                'verified' => (bool) ($profiles[$key]['verified'] ?? false),
            ];
        }

        $fallback = (string) array_key_first($profiles ?: ['goip-1' => []]);

        return ['key' => $fallback] + [
            'lines' => (int) ($profiles[$fallback]['lines'] ?? 1),
            'verified' => (bool) ($profiles[$fallback]['verified'] ?? false),
        ];
    }

    /**
     * The smallest free inbound path number (>= gateway_inbound_start) inside
     * the organization, so it never collides with an employee extension.
     */
    public function allocateInboundNumber(Organization $organization): string
    {
        $start = (int) config('integrations.telephony.gateway_inbound_start', 5000);

        $taken = GatewayLine::query()
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('inbound_number')
            ->pluck('inbound_number')
            ->map(fn ($value) => (int) $value)
            ->all();

        $taken = array_flip(array_merge(
            $taken,
            Extension::query()
                ->where('organization_id', $organization->getKey())
                ->pluck('extension')
                ->map(fn ($value) => (int) $value)
                ->all(),
        ));

        $candidate = $start;

        while (isset($taken[$candidate])) {
            $candidate++;
        }

        return (string) $candidate;
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    */

    /**
     * Reconcile one gateway line with the server.
     *
     * Returns false when the line is administratively blocked (inactive
     * organization or an unusable inbound destination): in that case nothing is
     * pushed to the server, so an existing, working line is never silently
     * switched off. The block is recorded with a clear, localisable reason.
     */
    public function syncLine(GatewayLine $line): bool
    {
        $organization = $line->organization;
        $device = $line->device;

        if (! $organization || ! $device) {
            return false;
        }

        if (! $this->destinationIsUsable($line)) {
            $line->forceFill([
                'provisioning_status' => 'failed',
                'provisioning_error' => 'inbound_destination_unavailable',
            ])->save();
            $device->recomputeStatus();

            return false;
        }

        if (! $organization->isActive()) {
            $line->forceFill([
                'provisioning_status' => 'failed',
                'provisioning_error' => 'organization_inactive',
            ])->save();
            $device->recomputeStatus();

            return false;
        }

        $this->provisioning->ensureOrganizationContext($organization);
        $organization->refresh();

        $this->credentials->ensure($line);
        $line->refresh();

        if (! $line->inbound_number) {
            $line->inbound_number = $this->allocateInboundNumber($organization);
        }

        $revision = (int) $line->provisioning_revision + 1;

        $line->forceFill([
            'inbound_number' => $line->inbound_number,
            'provisioning_revision' => $revision,
            'provisioning_status' => 'pending',
            'provisioning_error' => null,
        ])->save();

        $payload = $this->linePayload($line, $revision, $line->enabled && $device->enabled);

        ProvisionTelephonyJob::dispatch(
            'provision_gateway_line',
            $organization->getKey(),
            $payload,
            $this->lineKey($line, $payload),
            null,
            $device->getKey(),
            $line->getKey(),
        );

        $device->recomputeStatus();

        return true;
    }

    /**
     * Reconcile every line of a device.
     */
    public function syncDevice(GatewayDevice $device): void
    {
        foreach ($device->lines()->get() as $line) {
            $this->syncLine($line);
        }

        $device->recomputeStatus();
    }

    /**
     * Enable/disable a single line while keeping its identity (so re-enabling
     * re-provisions for real instead of assuming it is still there).
     */
    public function setLineEnabled(GatewayLine $line, bool $enabled): void
    {
        $line->forceFill(['enabled' => $enabled])->save();

        if ($enabled) {
            $this->syncLine($line);

            return;
        }

        $this->dispatchDisabled($line);
    }

    /**
     * Enable/disable the whole device (its lines follow).
     */
    public function setDeviceEnabled(GatewayDevice $device, bool $enabled): void
    {
        $device->forceFill(['enabled' => $enabled])->save();

        foreach ($device->lines()->get() as $line) {
            $line->forceFill(['enabled' => $enabled])->save();

            if ($enabled) {
                $this->syncLine($line);
            } else {
                $this->dispatchDisabled($line);
            }
        }

        $device->recomputeStatus();
    }

    /**
     * Rotate the SIP password of a line and push the new secret.
     */
    public function rotateLineSecret(GatewayLine $line): bool
    {
        $this->credentials->ensure($line, force: true);

        return $this->syncLine($line->refresh());
    }

    /**
     * Remove a line from the server entirely (its identity is not reused).
     */
    public function deprovisionLine(GatewayLine $line): void
    {
        $organization = $line->organization;

        if (! $organization || ! $line->sip_username) {
            return;
        }

        ProvisionTelephonyJob::dispatch(
            'deprovision_gateway_line',
            $organization->getKey(),
            [
                'organization_id' => $organization->getKey(),
                'sip_username' => $line->sip_username,
                'context' => $line->inboundContext(),
                'revision' => (int) $line->provisioning_revision,
            ],
            'deprovision:gateway-line:'.$line->getKey().':'.$line->sip_username,
            null,
            $line->gateway_device_id,
            $line->getKey(),
        );
    }

    /**
     * Remove a device and every line it owns from the server.
     */
    public function deleteDevice(GatewayDevice $device): void
    {
        foreach ($device->lines()->get() as $line) {
            $this->deprovisionLine($line);
        }

        $device->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Registration monitoring
    |--------------------------------------------------------------------------
    */

    /**
     * Read the real Asterisk registration snapshot and store it.
     *
     * When the monitoring source is unavailable the reading is cleared rather
     * than guessed, so the UI shows "unknown" instead of "disconnected".
     *
     * @return array{ok: bool, message?: string, source?: string, lines?: int, capabilities?: array<int, string>}
     */
    public function refreshStatus(GatewayDevice $device): array
    {
        $organization = $device->organization;

        if (! $organization) {
            return ['ok' => false, 'message' => 'no_organization'];
        }

        $lines = $device->lines()->whereNotNull('sip_username')->get();

        if ($lines->isEmpty()) {
            return ['ok' => false, 'message' => 'no_lines'];
        }

        $capabilities = $this->capabilities(true);

        $device->forceFill(['agent_capabilities' => $capabilities['capabilities']])->save();

        if (! in_array('gateway_status', $capabilities['capabilities'], true)) {
            $this->markMonitoringUnavailable($device, $lines, 'agent_unsupported');

            return [
                'ok' => false,
                'message' => 'agent_update_required',
                'capabilities' => $capabilities['capabilities'],
            ];
        }

        $result = $this->gateway->gatewayStatus([
            'organization_id' => $organization->getKey(),
            'sip_usernames' => $lines->pluck('sip_username')->values()->all(),
        ]);

        $source = (string) ($result['source'] ?? 'unavailable');

        if ($source === 'unavailable') {
            $this->markMonitoringUnavailable($device, $lines, 'monitoring_unavailable');

            return ['ok' => false, 'message' => 'monitoring_unavailable'];
        }

        $observedAt = now();
        $states = [];

        foreach ($lines as $line) {
            $row = $result['lines'][$line->sip_username] ?? null;
            $state = is_array($row) ? (string) ($row['state'] ?? 'unknown') : 'unknown';

            $line->forceFill([
                'registration_state' => $state,
                'registration_observed_at' => $observedAt,
                'registration_meta' => is_array($row) ? $row : null,
            ])->save();

            $states[] = $state;
        }

        $device->forceFill([
            'monitoring_source' => $source,
            'registration_state' => $this->aggregateRegistration($states),
            'registration_observed_at' => $observedAt,
        ])->save();

        return ['ok' => true, 'source' => $source, 'lines' => count($states)];
    }

    /*
    |--------------------------------------------------------------------------
    | Payloads
    |--------------------------------------------------------------------------
    */

    /**
     * The persisted payload of a gateway-line reconcile.
     *
     * It carries identifiers, the generated inbound number/context and the
     * revision — never the SIP password (injected by the job at run time).
     *
     * @return array<string, mixed>
     */
    public function linePayload(GatewayLine $line, int $revision, bool $enabled): array
    {
        $organization = $line->organization;
        $device = $line->device;

        return [
            'organization_id' => $organization?->getKey(),
            'organization_context' => $organization?->telephony_context,
            'gateway_id' => $device?->getKey(),
            'gateway_label' => $device?->label,
            'line_id' => $line->getKey(),
            'line_index' => $line->line_index,
            'context' => $line->inboundContext(),
            'inbound_number' => $line->inbound_number,
            'destination_username' => $line->destination?->sip_username,
            'label' => $line->label,
            'enabled' => $enabled && $organization?->isActive() === true,
            'revision' => $revision,
        ];
    }

    /**
     * The inbound destination must be an active extension that belongs to the
     * *same* organization as the line.
     */
    public function destinationIsUsable(GatewayLine $line): bool
    {
        $destination = $line->destination;

        return $destination instanceof Extension
            && (int) $destination->organization_id === (int) $line->organization_id
            && $destination->status === 'active'
            && (bool) $destination->sip_username;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Disable a line on the server without dropping its identity.
     */
    private function dispatchDisabled(GatewayLine $line): void
    {
        $device = $line->device;
        $organization = $line->organization;

        if (! $organization) {
            return;
        }

        $this->provisioning->ensureOrganizationContext($organization);
        $this->credentials->ensure($line);
        $line->refresh();

        if (! $line->inbound_number) {
            $line->inbound_number = $this->allocateInboundNumber($organization);
        }

        $revision = (int) $line->provisioning_revision + 1;

        $line->forceFill([
            'inbound_number' => $line->inbound_number,
            'provisioning_revision' => $revision,
            'provisioning_status' => 'pending',
            'provisioning_error' => null,
        ])->save();

        // A disabled line still needs a destination so the agent can rebuild the
        // (blocked) context; the manager also keeps the last known one.
        $payload = $this->linePayload($line, $revision, false);

        ProvisionTelephonyJob::dispatch(
            'provision_gateway_line',
            $organization->getKey(),
            $payload,
            $this->lineKey($line, $payload),
            null,
            $device?->getKey(),
            $line->getKey(),
        );

        $device?->recomputeStatus();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, GatewayLine>  $lines
     */
    private function markMonitoringUnavailable(GatewayDevice $device, $lines, string $reason): void
    {
        foreach ($lines as $line) {
            $line->forceFill([
                'registration_state' => 'unknown',
                'registration_observed_at' => null,
                'registration_meta' => ['reason' => $reason],
            ])->save();
        }

        $device->forceFill([
            'monitoring_source' => 'unavailable',
            'registration_state' => 'unknown',
            'registration_observed_at' => null,
        ])->save();
    }

    /**
     * @param  array<int, string>  $states
     */
    private function aggregateRegistration(array $states): string
    {
        if ($states === []) {
            return 'unknown';
        }

        $known = array_values(array_filter($states, fn (string $state) => $state !== 'unknown'));

        if ($known === []) {
            return 'unknown';
        }

        if (count(array_filter($known, fn (string $state) => $state === 'registered')) === count($states)) {
            return 'registered';
        }

        return 'degraded';
    }

    /**
     * The key includes a non-reversible digest of the password so rotating the
     * secret re-syncs without ever persisting the secret itself.
     *
     * @param  array<string, mixed>  $payload
     */
    private function lineKey(GatewayLine $line, array $payload): string
    {
        $digest = substr(sha1('gateway:'.(string) $line->sip_password), 0, 16);

        return 'gateway-line:'.$line->getKey().':'.$digest.':'.substr(sha1((string) json_encode($payload)), 0, 16);
    }
}
