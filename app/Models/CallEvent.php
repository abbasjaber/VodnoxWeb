<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallEvent extends Model
{
    protected $fillable = ['call_id', 'event_type', 'data', 'occurred_at'];

    protected $casts = [
        'data' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
