<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProvisioningJob extends Model
{
    protected $fillable = [
        'type', 'organization_id', 'payload', 'status',
        'idempotency_key', 'error_message', 'completed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'completed_at' => 'datetime',
    ];
}
