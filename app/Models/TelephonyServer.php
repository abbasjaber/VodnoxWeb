<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelephonyServer extends Model
{
    protected $fillable = [
        'name', 'host', 'port', 'connection_method',
        'ami_username', 'ami_secret', 'status', 'last_connected_at',
    ];

    protected $casts = [
        'last_connected_at' => 'datetime',
        // Credentials are encrypted at rest and never serialized.
        'ami_secret' => 'encrypted',
    ];

    protected $hidden = ['ami_secret'];
}
