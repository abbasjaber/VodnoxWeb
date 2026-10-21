<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The inbound routing of ONE gateway line (one SIM port).
 *
 * This is the single source of truth for "where does a call arriving on this
 * SIM go": the Asterisk dialplan is generated from these rows, and the
 * administrator's preview button resolves against the very same rows — so the
 * preview can never disagree with the real call.
 *
 * Every destination is a foreign key to an extension of the *same*
 * organization. A missing or disabled destination is therefore never silently
 * replaced by another tenant or by an outbound path: it resolves to the
 * configured fallback, or the call is refused with a readable reason.
 */
class GatewayLineRoute extends Model
{
    protected $fillable = [
        'organization_id', 'gateway_line_id',
        'default_destination_extension_id',
        'outside_hours_destination_extension_id',
        'weekend_destination_extension_id',
        'fallback_destination_extension_id',
        'temporary_forward_enabled',
        'temporary_forward_extension_id',
    ];

    protected $casts = [
        'temporary_forward_enabled' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(GatewayLine::class, 'gateway_line_id');
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(GatewayLineBusinessHour::class)->orderBy('day')->orderBy('start_time');
    }

    public function weekendDays(): HasMany
    {
        return $this->hasMany(GatewayLineWeekendDay::class)->orderBy('day');
    }

    public function dateExceptions(): HasMany
    {
        return $this->hasMany(GatewayLineDateException::class)->orderBy('starts_on');
    }

    public function defaultDestination(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'default_destination_extension_id');
    }

    public function outsideHoursDestination(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'outside_hours_destination_extension_id');
    }

    public function weekendDestination(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'weekend_destination_extension_id');
    }

    public function fallbackDestination(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'fallback_destination_extension_id');
    }

    public function temporaryForwardDestination(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'temporary_forward_extension_id');
    }

    /**
     * The timezone rules are evaluated in: the organization's, never the
     * browser's or the server's.
     */
    public function timezone(): string
    {
        return (string) ($this->organization?->timezone ?: 'Asia/Baghdad');
    }

    /**
     * Whether any business-hours window or weekend day is configured at all.
     * Without one, priority 3 cannot decide and the default destination wins.
     */
    public function hasWeeklyRules(): bool
    {
        return $this->businessHours()->exists() || $this->weekendDays()->exists();
    }
}
