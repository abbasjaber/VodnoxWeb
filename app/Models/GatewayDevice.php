<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GSM gateway device (GoIP and similar) that turns mobile calls into SIP.
 *
 * The device row only holds the desired state (`enabled`) and the *observed*
 * provisioning/registration/test states. Registration is only ever reported as
 * `registered` when a real Asterisk contact was seen; when the monitoring
 * source is unavailable the row goes to `unknown`, never `disconnected`.
 */
class GatewayDevice extends Model
{
    /** A registration older than this is no longer trusted as current. */
    public const STALE_AFTER_SECONDS = 180;

    protected $fillable = [
        'organization_id', 'created_by', 'label', 'model', 'max_lines', 'firmware',
        'network_notes', 'enabled',
        'provisioning_status', 'provisioning_revision', 'provisioning_error', 'provisioned_at',
        'registration_state', 'registration_observed_at', 'monitoring_source',
        'test_status', 'test_message', 'test_checked_at',
        'agent_capabilities',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'max_lines' => 'integer',
        'provisioning_revision' => 'integer',
        'provisioned_at' => 'datetime',
        'registration_observed_at' => 'datetime',
        'test_checked_at' => 'datetime',
        'agent_capabilities' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GatewayLine::class)->orderBy('line_index');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the registration reading is still fresh enough to be trusted.
     */
    public function registrationIsStale(): bool
    {
        if ($this->registration_observed_at === null) {
            return true;
        }

        return $this->registration_observed_at->diffInSeconds(now()) > self::STALE_AFTER_SECONDS;
    }

    /**
     * The honest registration label: an old or missing reading is unknown, not
     * "disconnected".
     */
    public function registrationLabel(): string
    {
        if ($this->registrationIsStale()) {
            return 'unknown';
        }

        return $this->registration_state ?: 'unknown';
    }

    public function isActive(): bool
    {
        return $this->enabled && $this->organization?->isActive() === true;
    }

    /**
     * The device-level provisioning state is the aggregate of its lines, so a
     * device is only "synced" when every one of its lines is.
     */
    public function recomputeStatus(): void
    {
        $lines = $this->lines()->get();

        if ($lines->isEmpty()) {
            return;
        }

        $failed = $lines->contains(fn (GatewayLine $line) => $line->provisioning_status === 'failed');
        $pending = $lines->contains(fn (GatewayLine $line) => in_array($line->provisioning_status, ['pending', 'failed'], true));

        $this->forceFill([
            'provisioning_status' => match (true) {
                $failed => 'failed',
                $pending => 'pending',
                default => 'synced',
            },
            'provisioning_error' => $failed
                ? $lines->firstWhere('provisioning_status', 'failed')?->provisioning_error
                : null,
            'provisioned_at' => $failed ? null : now(),
        ])->save();
    }
}
