<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    protected $fillable = ['code', 'name_ar', 'name_en', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizationFeatureSubscription::class);
    }
}
