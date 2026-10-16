<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormField extends Model
{
    protected $fillable = [
        'form_id', 'type', 'label_ar', 'label_en', 'help_text_ar', 'help_text_en',
        'placeholder_ar', 'placeholder_en', 'is_required', 'order',
        'min_length', 'max_length', 'min_value', 'max_value', 'max_rating', 'max_selections',
    ];

    protected $casts = ['is_required' => 'boolean'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(FormOption::class)->orderBy('order');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(FormCondition::class);
    }
}
