<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingScheduleDay extends Model
{
    protected $fillable = ['routing_schedule_id', 'day', 'start_time', 'end_time'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RoutingSchedule::class, 'routing_schedule_id');
    }
}
