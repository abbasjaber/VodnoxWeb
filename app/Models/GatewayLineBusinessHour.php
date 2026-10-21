<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business-hours window of a gateway line route.
 *
 * Semantics are the ones the existing ScheduleMatcher already implements and
 * tests: the end of a window is EXCLUSIVE, and a window whose end is <= its
 * start crosses midnight (Sat 22:00-02:00 covers Sunday 01:00).
 */
class GatewayLineBusinessHour extends Model
{
    protected $fillable = ['gateway_line_route_id', 'day', 'start_time', 'end_time'];

    protected $casts = ['day' => 'integer'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(GatewayLineRoute::class, 'gateway_line_route_id');
    }
}
