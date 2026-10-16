<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Development super-admin account with fixed credentials.
 *
 * This seeder refuses to run outside the "local" and "testing" environments so a
 * known password can never reach a production database.
 *
 *   php artisan db:seed --class=AdminSeeder
 *
 * Account created (password in parentheses):
 *   - admin  (Admin#2026)  platform super admin, no organization
 *
 * For production, create the super admin interactively instead:
 *   php artisan vodnox:create-admin
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {


        $this->call(RoleAndPermissionSeeder::class);

        $role = Role::where('name', 'super_admin')->firstOrFail();

        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Platform Admin',
                'email' => 'admin@vodnox.test',
                'password' => Hash::make('Admin#2026'),
                'role_id' => $role->id,
                'organization_id' => null,
                'active' => true,
            ],
        );
    }
}
