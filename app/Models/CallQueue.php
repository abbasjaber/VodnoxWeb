<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallQueue extends Model
{
    protected $fillable = [
        'organization_id', 'name_ar', 'name_en', 'strategy', 'timeout', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'call_queue_members', 'call_queue_id', 'user_id')
            ->withPivot('order')
            ->withTimestamps();
    }

    public function memberRecords(): HasMany
    {
        return $this->hasMany(CallQueueMember::class);
    }
}
