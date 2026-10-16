<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'organization_id',
        'role_id',
        'name',
        'username',
        'email',
        'password',
        'active',
        'extension',
        'phone',
        'position',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }

    /**
     * The telephony asset bound to this account, if any.
     */
    public function primaryExtension(): ?Extension
    {
        return $this->extensions()->whereNull('disabled_at')->orderBy('id')->first()
            ?? $this->extensions()->orderBy('id')->first();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->name === 'super_admin' || $this->organization_id === null;
    }

    public function isOrganizationAdmin(): bool
    {
        return $this->role?->name === 'organization_admin';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return $this->role?->permissions()->where('name', $permission)->exists() ?? false;
    }

    public function getDashboardRoute(): string
    {
        return match ($this->role?->name) {
            'super_admin' => 'admin.index',
            'organization_admin' => 'company.index',
            default => 'user.index',
        };
    }
}
