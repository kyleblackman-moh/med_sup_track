<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration for permission renames:
 *   case.reassign      -> assignment.reassign
 *   case.intake_route  -> case.intake  (case.route_to_review is new; the seeder creates it)
 *
 * Renaming in place keeps any existing role or user assignments attached.
 * On a fresh database the table is empty and this is a no-op.
 */
return new class extends Migration
{
    private const RENAMES = [
        'case.reassign' => 'assignment.reassign',
        'case.intake_route' => 'case.intake',
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
            // Skip if the target already exists (e.g. the seeder ran first).
            if (DB::table($table)->where('name', $to)->exists()) {
                continue;
            }

            DB::table($table)->where('name', $from)->update(['name' => $to, 'updated_at' => now()]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
