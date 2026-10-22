<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The independent technical sign-in of one organization's "Telephony Technical
 * Settings" section.
 *
 * It is its own authenticatable identity on its own guard (`technical`), so it
 * can never be confused with a website account, can never inherit a website
 * session, and can never reach another organization or the super-admin panel:
 * every technical route re-checks the bound organization.
 *
 * The password is one-way hashed (Laravel `hashed` cast -> bcrypt) and hidden
 * from serialization; the plain value exists only in the moment it is generated
 * for the super admin and is never stored.
 */
class TelephonyTechnicalAccount extends Authenticatable
{
    protected $fillable = [
        'organization_id',
        'created_by',
        'username',
        'password',
        'active',
        'session_version',
        'password_changed_at',
        'last_login_at',
        'disabled_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'session_version' => 'integer',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
