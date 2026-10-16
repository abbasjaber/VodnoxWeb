<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProof extends Model
{
    protected $fillable = ['payment_id', 'file_path', 'file_name', 'mime_type', 'size'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
