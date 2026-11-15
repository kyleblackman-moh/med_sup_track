<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 10d: what kind of query a PS return to the CMO is.
 *
 * return_kind is NULL on every row except a PS return (WITH_PS ->
 * RETURNED_TO_CMO), where it is 'legal' or 'technical'. Written once, on the
 * insert; the append-only trigger (trg_case_transitions_append_only, INSTEAD
 * OF UPDATE, DELETE) is table-level and is not touched here, so it keeps
 * blocking UPDATE and DELETE on every column, this one included.
 *
 * Forward-only in practice.
 */
return new class extends Migration
{
    private const CHECK = 'ck_case_transitions_return_kind';

    public function up(): void
    {
        Schema::table('case_transitions', function (Blueprint $table) {
            $table->string('return_kind', 20)->nullable();
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE case_transitions ADD CONSTRAINT '.self::CHECK
                ." CHECK (return_kind IS NULL OR return_kind IN ('legal', 'technical'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE case_transitions DROP CONSTRAINT '.self::CHECK);
        }

        Schema::table('case_transitions', function (Blueprint $table) {
            $table->dropColumn('return_kind');
        });
    }
};
