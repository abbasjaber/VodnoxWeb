<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
