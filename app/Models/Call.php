<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Call extends Model
{
    protected $fillable = [
        'organization_id', 'external_call_id', 'user_id', 'customer_id',
        'direction', 'caller_number', 'callee_number', 'status',
        'started_at', 'answered_at', 'ended_at', 'duration_seconds',
        'billable_seconds', 'recording_path', 'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'billable_seconds' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(Recording::class);
    }

    public function voicemails(): HasMany
    {
        return $this->hasMany(Voicemail::class);
    }

    public function callNotes(): HasMany
    {
        return $this->hasMany(CallNote::class, 'call_id');
    }

    public function wrapup(): HasOne
    {
        return $this->hasOne(CallWrapup::class, 'call_id');
    }
}
