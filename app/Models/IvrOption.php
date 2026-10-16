<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IvrOption extends Model
{
    protected $fillable = [
        'ivr_menu_id', 'digit', 'destination_type', 'destination_id',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(IvrMenu::class, 'ivr_menu_id');
    }
}
