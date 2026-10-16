<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'contact_name', 'email', 'phone',
        'city', 'status', 'timezone', 'language', 'internal_notes', 'activated_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function featureSubscriptions(): HasMany
    {
        return $this->hasMany(OrganizationFeatureSubscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trial']);
    }

    public function hasFeature(string $featureCode): bool
    {
        return $this->featureSubscriptions()
            ->whereHas('feature', fn($q) => $q->where('code', $featureCode))
            ->where('status', 'active')
            ->exists();
    }
}
