<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $identity_verification_manager_permissions = [
            'security_personnel:index',
            'security_personnel:show',
            'security_personnel:store',
            'security_personnel:update',
            'security_personnel:delete',
        ];
        $weapon_delivery_manager_permissions = [
            'weapon_delivery_point:index',
            'weapon_delivery_point:show',
            'weapon_delivery_point:store',
            'weapon_delivery_point:update',
            'weapon_delivery_point:delete',
            'weapon_delivery:index',
            'weapon_delivery:show',
            'weapon_delivery:store',
            'weapon_delivery:update',
            'weapon_delivery:delete',
        ];
        $security_personnel_permissions = [
            'qr_code:generate',
        ];

        $citizen_permissions = [
            'weapon_delivery:store',
        ];

        $permissionsByRole = [
            'admin' => [
                ...$identity_verification_manager_permissions,
                'personnel:force-delete',
                ...$weapon_delivery_manager_permissions,
                'weapon_delivery:force-delete',
            ],
            'identity_verification_manager' => [
                ...$identity_verification_manager_permissions
            ],
            'weapon_delivery_manager' => [
                ...$weapon_delivery_manager_permissions
            ],
            'security_personnel' => [
                ...$security_personnel_permissions
            ],
            'citizen' => [
                ...$citizen_permissions,
            ],
        ];
        foreach ($permissionsByRole as $role => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $role,
            ]);
            foreach ($permissions as $p) {
                Permission::firstOrCreate([
                    'name' => $p,
                ]);
                $role->givePermissionTo($p);
            }
        }
    }
}
