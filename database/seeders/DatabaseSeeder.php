<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed reference data only. No default credentials are created here:
     * the production super admin is created with `php artisan vodnox:create-admin`.
     */
    public function run(): void
    {
        $this->call(RoleAndPermissionSeeder::class);

        $this->seedFeatures();
    }

    private function seedFeatures(): void
    {
        $features = [
            ['code' => 'crm', 'name_ar' => 'إدارة علاقات العملاء', 'name_en' => 'CRM'],
            ['code' => 'forms', 'name_ar' => 'نماذج VODNOX', 'name_en' => 'VODNOX FORMS'],
            ['code' => 'smart_ivr', 'name_ar' => 'المجيب الآلي الذكي', 'name_en' => 'Smart IVR'],
        ];

        foreach ($features as $feature) {
            Feature::updateOrCreate(['code' => $feature['code']], $feature + ['is_active' => true]);
        }
    }
}
