<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormCondition extends Model
{
    protected $fillable = ['form_field_id', 'depends_on_field_id', 'operator', 'value', 'action'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    public function dependsOnField(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'depends_on_field_id');
    }
}
