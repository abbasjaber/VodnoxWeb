<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A telephony extension.
 *
 * Three separate states are tracked and must not be conflated:
 *  - `status`               lifecycle of the asset (inactive/active/...)
 *  - `provisioning_status`  synchronisation with the telephony server
 *  - `last_registered_at`   last time a device actually registered
 *
 * `sip_password` is encrypted at rest and never serialized.
 */
class Extension extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'extension', 'label', 'sip_secret', 'status',
        'sip_username', 'sip_password', 'sip_domain',
        'provisioning_status', 'provisioning_error', 'provisioned_at',
        'disabled_at', 'last_registered_at',
    ];

    protected $hidden = ['sip_secret', 'sip_password'];

    protected $casts = [
        'sip_password' => 'encrypted',
        'provisioned_at' => 'datetime',
        'disabled_at' => 'datetime',
        'last_registered_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * A device can register only when the asset is active and the server-side
     * account has been provisioned and not disabled.
     */
    public function isDialable(): bool
    {
        return $this->status === 'active'
            && $this->provisioning_status === 'synced'
            && $this->disabled_at === null;
    }
}
