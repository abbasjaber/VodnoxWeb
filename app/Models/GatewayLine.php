<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One physical line (one SIM) on a gateway device.
 *
 * Each line owns a separate SIP account and a separate inbound destination, so
 * an incoming call on SIM A can never be routed to the destination chosen for
 * SIM B. `sip_password` is encrypted at rest and never serialised into JSON,
 * job payloads, audit rows or exceptions.
 */
class GatewayLine extends Model
{
    protected $fillable = [
        'gateway_device_id', 'organization_id', 'line_index', 'label', 'sim_number',
        'inbound_number', 'inbound_extension_id',
        'sip_username', 'sip_password', 'sip_domain', 'sip_host', 'sip_port', 'sip_transport',
        'enabled',
        'provisioning_status', 'provisioning_revision', 'provisioning_error', 'provisioned_at',
        'registration_state', 'registration_observed_at', 'registration_meta',
        'test_status', 'test_message', 'test_checked_at',
    ];

    protected $hidden = ['sip_password'];

    protected $casts = [
        'sip_password' => 'encrypted',
        'enabled' => 'boolean',
        'line_index' => 'integer',
        'sip_port' => 'integer',
        'provisioning_revision' => 'integer',
        'provisioned_at' => 'datetime',
        'registration_observed_at' => 'datetime',
        'test_checked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(GatewayDevice::class, 'gateway_device_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'inbound_extension_id');
    }

    /**
     * The inbound routing of this port (default / hours / exceptions). Created
     * lazily so a port added before routing exists still has exactly one row.
     */
    public function route(): HasOne
    {
        return $this->hasOne(GatewayLineRoute::class);
    }

    public function registrationIsStale(): bool
    {
        if ($this->registration_observed_at === null) {
            return true;
        }

        return $this->registration_observed_at->diffInSeconds(now()) > GatewayDevice::STALE_AFTER_SECONDS;
    }

    public function registrationLabel(): string
    {
        if ($this->registrationIsStale()) {
            return 'unknown';
        }

        return $this->registration_state ?: 'unknown';
    }

    /**
     * ONE honest summary state per port.
     *
     * The three dimensions stay separate on purpose — `enabled` is what the
     * administrator asked for, `provisioning_status` is what the server was
     * told, `registrationLabel()` is what was actually observed — and this
     * method only folds them into a single chip:
     *
     *   unused        the administrator has not marked this port as in use
     *                 (no SIM assumed: a port without a SIM is never dialled);
     *   configuring   provisioning is queued / in flight;
     *   configured    provisioned and reconciled, but no registration reading
     *                 has arrived yet (no false "connected" claim);
     *   registered    a real Asterisk contact was seen recently;
     *   unregistered  the device was seen and is not registered;
     *   unknown       the monitoring source is unavailable or the reading is
     *                 stale — never reported as "disconnected";
     *   blocked       provisioning refused for a readable reason.
     */
    public function portState(): string
    {
        if (! $this->enabled) {
            return 'unused';
        }

        if ($this->provisioning_status === 'failed') {
            return 'blocked';
        }

        if (in_array($this->provisioning_status, ['removed', 'disabled'], true)) {
            return 'unused';
        }

        if ($this->provisioning_status !== 'synced') {
            return 'configuring';
        }

        $observed = $this->registrationLabel();

        if ($observed === 'registered') {
            return 'registered';
        }

        if (in_array($observed, ['no_contact', 'unreachable', 'auth_failure'], true)) {
            return 'unregistered';
        }

        // Provisioned, registered state genuinely unknown: distinguish "we have
        // never looked" from "the monitoring source could not answer".
        if ($this->registration_observed_at === null && ! $this->registration_state) {
            return 'configured';
        }

        return 'unknown';
    }

    /**
     * The dedicated inbound dialplan context for this line.
     *
     * Bound to the organization on the server side so the agent can verify
     * ownership before it touches anything.
     */
    public function inboundContext(): string
    {
        return 'vodnox_gw_'.$this->organization_id.'_'.$this->id;
    }
}
