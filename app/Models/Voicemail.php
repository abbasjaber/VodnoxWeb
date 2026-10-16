<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voicemail extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'caller_number', 'duration_seconds',
        'file_path', 'is_read',
    ];

    protected $casts = ['is_read' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
