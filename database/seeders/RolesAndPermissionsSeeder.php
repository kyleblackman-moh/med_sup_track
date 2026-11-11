<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionName;
use App\Enums\Role as RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds roles and permissions from App\Enums\Role and App\Enums\Permission.
 * Idempotent: safe to run repeatedly; role permissions are synced to the matrix.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, self::GUARD);
        }

        foreach (RoleName::cases() as $roleName) {
            Role::findOrCreate($roleName->value, self::GUARD)
                ->syncPermissions($roleName->permissionNames());
        }

        $registrar->forgetCachedPermissions();
    }
}
