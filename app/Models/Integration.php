<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $fillable = ['type', 'name', 'config', 'is_active'];

    protected $casts = [
        // Integration config holds credentials (SMTP passwords, API tokens)
        // so it is encrypted at rest; it is never echoed back to the UI.
        'config' => 'encrypted:array',
        'is_active' => 'boolean',
    ];
}
