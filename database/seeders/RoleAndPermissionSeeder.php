<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'admin' => ['view_organizations', 'manage_organizations', 'view_plans', 'manage_plans', 'view_invoices', 'manage_invoices', 'view_servers', 'manage_servers', 'view_integrations', 'manage_integrations', 'view_audit', 'manage_settings'],
            'crm' => ['view_customers', 'manage_customers', 'view_opportunities', 'manage_opportunities', 'view_tasks', 'manage_tasks', 'view_reports'],
            'forms' => ['view_forms', 'manage_forms', 'view_submissions', 'export_submissions'],
            'communications' => ['view_numbers', 'manage_numbers', 'view_extensions', 'manage_extensions', 'view_queues', 'manage_queues', 'view_ivr', 'manage_ivr', 'view_routing', 'manage_routing', 'view_call_history', 'view_recordings', 'view_performance', 'view_gateways', 'manage_gateways', 'view_gateway_secrets', 'rotate_gateway_secrets'],
            'organization' => ['view_team', 'manage_team', 'view_subscription', 'manage_subscription', 'view_billing', 'manage_billing', 'view_settings', 'manage_settings'],
            'smart_ivr' => ['view_smart_ivr', 'manage_smart_ivr'],
        ];

        foreach ($modules as $module => $perms) {
            foreach ($perms as $perm) {
                Permission::firstOrCreate(
                    ['name' => $perm],
                    ['label_ar' => $perm, 'label_en' => $perm]
                );
            }
        }

        $roles = [
            'super_admin' => ['label_ar' => 'مشرف عام', 'label_en' => 'Super Admin', 'permissions' => Permission::pluck('name')->all()],
            'organization_admin' => ['label_ar' => 'مدير مؤسسة', 'label_en' => 'Organization Admin', 'permissions' => ['view_customers','manage_customers','view_opportunities','manage_opportunities','view_tasks','manage_tasks','view_reports','view_forms','manage_forms','view_submissions','export_submissions','view_numbers','manage_numbers','view_extensions','manage_extensions','view_queues','manage_queues','view_ivr','manage_ivr','view_routing','manage_routing','view_call_history','view_recordings','view_performance','view_gateways','manage_gateways','view_gateway_secrets','rotate_gateway_secrets','view_team','manage_team','view_subscription','manage_subscription','view_billing','manage_billing','view_settings','manage_settings','view_smart_ivr','manage_smart_ivr']],
            'supervisor' => ['label_ar' => 'مشرف', 'label_en' => 'Supervisor', 'permissions' => ['view_customers','manage_customers','view_opportunities','manage_opportunities','view_tasks','manage_tasks','view_reports','view_forms','manage_forms','view_submissions','view_call_history','view_recordings','view_performance','view_gateways','view_team','view_smart_ivr']],
            'agent' => ['label_ar' => 'موظف', 'label_en' => 'Agent', 'permissions' => ['view_customers','manage_customers','view_tasks','manage_tasks','view_forms','view_submissions','view_call_history','view_smart_ivr']],
            'viewer' => ['label_ar' => 'مشاهد', 'label_en' => 'Viewer', 'permissions' => ['view_customers','view_opportunities','view_tasks','view_reports','view_forms','view_submissions','view_call_history']],
        ];

        foreach ($roles as $name => $data) {
            $role = Role::firstOrCreate(
                ['name' => $name],
                ['label_ar' => $data['label_ar'], 'label_en' => $data['label_en']]
            );
            $permIds = Permission::whereIn('name', $data['permissions'])->pluck('id');
            $role->permissions()->sync($permIds);
        }
    }
}
