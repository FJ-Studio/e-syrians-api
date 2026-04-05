<?php

namespace Database\Seeders;

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

        $security_personnel_permissions = [
            'qr_code:generate',
        ];

        $permissionsByRole = [
            'admin' => [
                'personnel:force-delete',
            ],
            'security_personnel' => [
                ...$security_personnel_permissions,
            ],
            'local_census_manager' => [],
            'citizen' => [],
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
