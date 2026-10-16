<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationFeatureSubscription extends Model
{
    protected $table = 'organization_feature_subscriptions';

    protected $fillable = [
        'organization_id', 'feature_id', 'status',
        'starts_at', 'ends_at', 'max_users', 'max_customers',
        'max_forms', 'max_submissions', 'price', 'currency',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $withinStart = $this->starts_at === null || $this->starts_at->isPast();
        $withinEnd = $this->ends_at === null || $this->ends_at->isFuture();

        return $withinStart && $withinEnd;
    }
}
