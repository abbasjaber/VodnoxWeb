<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPreference extends Model
{
    protected $fillable = [
        'user_id', 'audio_device', 'auto_answer', 'do_not_disturb', 'settings',
    ];

    protected $casts = [
        'auto_answer' => 'boolean',
        'do_not_disturb' => 'boolean',
        'settings' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
