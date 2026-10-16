<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IvrMenu extends Model
{
    protected $fillable = [
        'organization_id', 'name_ar', 'name_en', 'greeting_ar', 'greeting_en',
        'timeout', 'max_attempts', 'invalid_destination_type', 'invalid_destination_id',
        'no_input_destination_type', 'no_input_destination_id', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(IvrOption::class);
    }
}
