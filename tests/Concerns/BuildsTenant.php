<?php

namespace Tests\Concerns;

use App\Models\Feature;
use App\Models\Organization;
use App\Models\OrganizationFeatureSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Helpers to build tenants (organizations, users, roles, feature
 * subscriptions) for tests. No credentials here are used in production; the
 * seeder only creates roles/permissions/features.
 */
trait BuildsTenant
{
    protected function role(string $name): Role
    {
        $labels = [
            'super_admin' => ['مشرف عام', 'Super Admin'],
            'organization_admin' => ['مدير مؤسسة', 'Organization Admin'],
            'supervisor' => ['مشرف', 'Supervisor'],
            'agent' => ['موظف', 'Agent'],
            'viewer' => ['مشاهد', 'Viewer'],
        ];

        [$ar, $en] = $labels[$name] ?? [$name, $name];

        return Role::firstOrCreate(
            ['name' => $name],
            ['label_ar' => $ar, 'label_en' => $en, 'scope' => $name === 'super_admin' ? 'platform' : 'organization'],
        );
    }

    protected function superAdmin(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Super Admin',
            'username' => 'super_'.Str::lower(Str::random(6)),
            'email' => Str::lower(Str::random(6)).'@vodnox.test',
            'password' => 'password123',
            'role_id' => $this->role('super_admin')->id,
            'organization_id' => null,
            'active' => true,
        ], $attributes));
    }

    protected function organization(array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'code' => 'ORG-'.Str::upper(Str::random(6)),
            'name_ar' => 'مؤسسة اختبار',
            'name_en' => 'Test Org',
            'status' => 'active',
            'timezone' => 'Asia/Baghdad',
            'language' => 'ar',
            'activated_at' => now(),
        ], $attributes));
    }

    protected function orgUser(Organization $organization, string $role, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($role).' '.Str::upper(Str::random(4)),
            'username' => $role.'_'.Str::lower(Str::random(6)),
            'email' => Str::lower(Str::random(6)).'@vodnox.test',
            'password' => 'password123',
            'role_id' => $this->role($role)->id,
            'organization_id' => $organization->id,
            'active' => true,
        ], $attributes));
    }

    protected function orgAdmin(Organization $organization, array $attributes = []): User
    {
        return $this->orgUser($organization, 'organization_admin', $attributes);
    }

    protected function feature(string $code): Feature
    {
        return Feature::firstOrCreate(
            ['code' => $code],
            ['name_ar' => $code, 'name_en' => strtoupper($code), 'is_active' => true],
        );
    }

    /**
     * Activate an independent feature subscription for an organization.
     */
    protected function activateFeature(Organization $organization, string $code, array $attributes = []): OrganizationFeatureSubscription
    {
        return OrganizationFeatureSubscription::updateOrCreate(
            ['organization_id' => $organization->id, 'feature_id' => $this->feature($code)->id],
            array_merge([
                'status' => 'active',
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ], $attributes),
        );
    }
}
