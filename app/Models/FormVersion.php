<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormVersion extends Model
{
    protected $fillable = ['form_id', 'version', 'schema', 'is_published', 'published_at'];

    protected $casts = [
        'schema' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
