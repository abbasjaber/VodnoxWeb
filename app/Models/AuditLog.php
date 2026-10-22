<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'technical_account_id', 'organization_id', 'action', 'auditable_type',
        'auditable_id', 'old_values', 'new_values', 'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The technical sign-in the action was performed under, when it happened
     * inside the Telephony Technical Settings section.
     */
    public function technicalAccount(): BelongsTo
    {
        return $this->belongsTo(TelephonyTechnicalAccount::class, 'technical_account_id');
    }
}
