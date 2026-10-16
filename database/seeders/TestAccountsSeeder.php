<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\OrganizationFeatureSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Test-only accounts for manual review.
 *
 * This seeder refuses to run outside the "local" and "testing" environments so
 * it can never create known-password users on a production database.
 *
 *   php artisan db:seed --class=TestAccountsSeeder
 *
 * Accounts created (password in parentheses):
 *   - superadmin  (SuperAdmin#2026)  platform super admin, no organization
 *   - orgadmin    (OrgAdmin#2026)    organization admin of "مؤسسة المراجعة"
 *   - agent1      (Agent#2026)       agent (extension 1001)
 *   - agent2      (Agent#2026)       agent (extension 1002)
 *
 * Criteria covered: the organization is active, CRM and FORMS are subscribed so
 * the gated workspaces can be reviewed, while Smart IVR is intentionally left
 * unsubscribed so the reviewer can confirm it stays hidden / "coming soon".
 */
class TestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('TestAccountsSeeder is restricted to local/testing environments.');
        }

        $this->call(RoleAndPermissionSeeder::class);

        foreach ([
            ['code' => 'crm', 'name_ar' => 'إدارة علاقات العملاء', 'name_en' => 'CRM'],
            ['code' => 'forms', 'name_ar' => 'نماذج VODNOX', 'name_en' => 'VODNOX FORMS'],
            ['code' => 'smart_ivr', 'name_ar' => 'المجيب الآلي الذكي', 'name_en' => 'Smart IVR'],
        ] as $feature) {
            Feature::updateOrCreate(['code' => $feature['code']], $feature + ['is_active' => true]);
        }

        $organization = Organization::updateOrCreate(
            ['code' => 'REVIEW-01'],
            [
                'name_ar' => 'مؤسسة المراجعة',
                'name_en' => 'Review Organization',
                'status' => 'active',
                'timezone' => 'Asia/Baghdad',
                'language' => 'ar',
                'activated_at' => now(),
            ],
        );

        // CRM + FORMS are subscribed; Smart IVR is not (stays hidden).
        foreach (['crm', 'forms'] as $code) {
            OrganizationFeatureSubscription::updateOrCreate(
                ['organization_id' => $organization->id, 'feature_id' => Feature::where('code', $code)->value('id')],
                ['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => null],
            );
        }

        $this->user('superadmin', 'Super Admin', 'superadmin@vodnox.test', 'SuperAdmin#2026', 'super_admin', null);
        $this->user('orgadmin', 'مدير المؤسسة', 'orgadmin@vodnox.test', 'OrgAdmin#2026', 'organization_admin', $organization->id);
        $this->user('agent1', 'موظف أول', 'agent1@vodnox.test', 'Agent#2026', 'agent', $organization->id, '1001');
        $this->user('agent2', 'موظف ثانٍ', 'agent2@vodnox.test', 'Agent#2026', 'agent', $organization->id, '1002');

        Department::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => 'SUPPORT'],
            ['name_ar' => 'الدعم الفني', 'name_en' => 'Support'],
        );
    }

    private function user(
        string $username,
        string $name,
        string $email,
        string $password,
        string $role,
        ?int $organizationId,
        ?string $extension = null,
    ): User {
        return User::updateOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role_id' => Role::where('name', $role)->value('id'),
                'organization_id' => $organizationId,
                'extension' => $extension,
                'active' => true,
            ],
        );
    }
}
