<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * IT-ADMIN follow-up: the System Admin no longer holds assignment.reassign or
 * delegation.manage (spec 4.2: the System Admin manages users and
 * configuration but cannot act on cases). The CMO keeps both; config.manage
 * stays with the System Admin.
 *
 * Detaches the grants from the role (the role_has_permissions rows). The
 * permissions themselves are untouched. RolesAndPermissionsSeeder syncs to the
 * same matrix (App\Enums\Role). On a fresh database this is a no-op.
 */
return new class extends Migration
{
    private const ROLE = 'system_admin';

    private const PERMISSIONS = ['assignment.reassign', 'delegation.manage'];

    public function up(): void
    {
        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();

        foreach ($this->existing() as $permission) {
            $role?->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();

        foreach ($this->existing() as $permission) {
            $role?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<Permission> */
    private function existing(): array
    {
        return Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->get()->all();
    }
};
