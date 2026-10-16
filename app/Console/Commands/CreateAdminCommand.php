<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommand extends Command
{
    protected $signature = 'vodnox:create-admin
                            {--name= : Admin name}
                            {--username= : Admin username}
                            {--email= : Admin email}
                            {--password= : Admin password}';

    protected $description = 'Create a super admin user';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Admin name', 'Super Admin');
        $username = $this->option('username') ?? $this->ask('Admin username', 'admin');
        $email = $this->option('email') ?? $this->ask('Admin email', 'admin@vodnox.test');
        $password = $this->option('password') ?? $this->secret('Admin password');

        if (empty($password)) {
            $this->error('Password is required.');
            return self::FAILURE;
        }

        $superAdminRole = Role::where('name', 'super_admin')->first();

        if (! $superAdminRole) {
            $this->error('super_admin role not found. Run the RoleAndPermissionSeeder first.');
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role_id' => $superAdminRole->id,
                'organization_id' => null,
                'active' => true,
            ]
        );

        $this->info("Super admin '{$username}' created successfully.");

        return self::SUCCESS;
    }
}
