<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 10c.
 *
 * 1. Hardcopy single source: hardcopy_received_on (the date), hardcopy_received_by
 *    (who recorded it) and hardcopy_received_at (when it was recorded) are either
 *    all NULL or all NOT NULL. Enforced by a CHECK constraint (sqlsrv).
 *    The migration refuses to run while any row breaks the rule: fix that row in
 *    the UI first. It never changes data itself.
 *
 * 2. file_no is no longer unique: the CMO's office says it is a fixed series
 *    (HE:8/2/50) and the same number may appear on more than one case.
 *    down() puts the filtered unique index back.
 *
 * Forward-only in practice. No ON DELETE RESTRICT (SQL Server has none).
 */
return new class extends Migration
{
    private const CHECK = 'ck_cases_hardcopy_all_or_none';

    private const RULE = '(hardcopy_received_on IS NULL AND hardcopy_received_by IS NULL AND hardcopy_received_at IS NULL)'
        .' OR (hardcopy_received_on IS NOT NULL AND hardcopy_received_by IS NOT NULL AND hardcopy_received_at IS NOT NULL)';

    public function up(): void
    {
        $violating = DB::table('cases')->whereRaw('NOT ('.self::RULE.')')->pluck('reference_no', 'id');
        if ($violating->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add '.self::CHECK.': these cases have a partial hardcopy record. Re-record the hardcopy in the UI first: '
                .$violating->map(fn ($ref, $id) => ($ref ?? '(no reference)')." ({$id})")->implode(', ')
            );
        }

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX cases_file_no_unique ON cases');
            DB::statement('ALTER TABLE cases ADD CONSTRAINT '.self::CHECK.' CHECK ('.self::RULE.')');
        } else {
            Schema::table('cases', fn (Blueprint $table) => $table->dropUnique('cases_file_no_unique'));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE cases DROP CONSTRAINT '.self::CHECK);
            DB::statement('CREATE UNIQUE INDEX cases_file_no_unique ON cases (file_no) WHERE file_no IS NOT NULL');
        } else {
            Schema::table('cases', fn (Blueprint $table) => $table->unique('file_no'));
        }
    }
};
