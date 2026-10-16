<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingScheduleTarget extends Model
{
    protected $fillable = ['routing_schedule_id', 'user_id', 'order', 'ring_duration'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RoutingSchedule::class, 'routing_schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
