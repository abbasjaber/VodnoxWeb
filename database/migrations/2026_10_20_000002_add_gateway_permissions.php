<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the independent permissions for managing GSM gateways.
 *
 * This is an additive, reversible migration (no fresh/reset/reseed): existing
 * installations get the new permissions and the correct role grants without
 * disturbing any other data.
 *
 * Separation of duties:
 *  - view_gateways           see the device/line inventory and its honest states
 *  - manage_gateways         add/update/remove devices and lines, retry/fix
 *  - view_gateway_secrets    reveal a line's SIP password on request
 *  - rotate_gateway_secrets  generate a new SIP password for a line
 *
 * A supervisor does not receive any of the secret permissions, so being inside
 * the company route group never exposes credentials by itself.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private const PERMISSIONS = [
        'view_gateways' => ['عرض بوابات الاتصال', 'View communication gateways'],
        'manage_gateways' => ['إدارة بوابات الاتصال', 'Manage communication gateways'],
        'view_gateway_secrets' => ['عرض أسرار بوابات الاتصال', 'View gateway secrets'],
        'rotate_gateway_secrets' => ['تدوير أسرار بوابات الاتصال', 'Rotate gateway secrets'],
    ];

    /** @var array<string, array<int, string>> */
    private const ROLE_GRANTS = [
        'organization_admin' => [
            'view_gateways',
            'manage_gateways',
            'view_gateway_secrets',
            'rotate_gateway_secrets',
        ],
        // Read-only visibility, deliberately without any secret permission.
        'supervisor' => ['view_gateways'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $name => [$labelAr, $labelEn]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'label_ar' => $labelAr,
                    'label_en' => $labelEn,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $ids = DB::table('permissions')
            ->whereIn('name', array_keys(self::PERMISSIONS))
            ->pluck('id', 'name');

        foreach (self::ROLE_GRANTS as $roleName => $permissionNames) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                $permissionId = $ids[$permissionName] ?? null;

                if (! $permissionId) {
                    continue;
                }

                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    [],
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', array_keys(self::PERMISSIONS))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
