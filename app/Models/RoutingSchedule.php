<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutingSchedule extends Model
{
    protected $fillable = [
        'organization_id', 'name_ar', 'name_en', 'strategy', 'is_active',
        'ring_duration', 'no_answer_type', 'no_answer_target_id',
        'fallback_ring_duration', 'outside_hours_type', 'outside_hours_target_id',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(RoutingScheduleDay::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(RoutingScheduleTarget::class)->orderBy('order');
    }

    public function isActiveNow(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $timezone = $this->organization?->timezone ?: 'Asia/Baghdad';
        $now = now()->setTimezone($timezone);
        $dayOfWeek = (int) $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        return $this->days
            ->where('day', $dayOfWeek)
            ->contains(function (RoutingScheduleDay $day) use ($currentTime) {
                $start = $day->start_time;
                $end = $day->end_time;

                if ($end > $start) {
                    return $start <= $currentTime && $currentTime < $end;
                }

                return $currentTime >= $start || $currentTime < $end;
            });
    }
}
