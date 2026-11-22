<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * IT-ADMIN: data migration for the IT Admin console's permission names.
 *
 *   user.create            -> users.create
 *   users.manage           -> users.view
 *   vendor_user.invite     -> users.invite_vendor
 *   user.reset_credentials -> admin.access   (retired; only system_admin held it,
 *                                              and system_admin is the holder of admin.access)
 *
 * Renaming in place keeps existing role assignments attached and deletes
 * nothing. The new names (users.update_role, users.disable, system.health.view,
 * jobs.retry) are created by RolesAndPermissionsSeeder, which also syncs every
 * role to App\Enums\Role::permissions(). On a fresh database this is a no-op.
 */
return new class extends Migration
{
    private const RENAMES = [
        'user.create' => 'users.create',
        'users.manage' => 'users.view',
        'vendor_user.invite' => 'users.invite_vendor',
        'user.reset_credentials' => 'admin.access',
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    private function rename(array $map): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        foreach ($map as $from => $to) {
            // Skip if the target already exists (for example the seeder ran first).
            if (DB::table($table)->where('name', $to)->exists()) {
                continue;
            }

            DB::table($table)->where('name', $from)->update(['name' => $to, 'updated_at' => now()]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
