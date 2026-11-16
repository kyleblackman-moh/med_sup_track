<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cleanup-1: drop the getdate() default on case_transitions.created_at, as was
 * done for case_assignments.assigned_at and case_track_events.created_at.
 * getdate() is server local time (4 hours behind UTC); CaseTransitionService
 * always writes UTC itself. Without a default, an insert that forgets the
 * value fails instead of silently taking local time.
 *
 * The constraint name is looked up in sys.default_constraints (SQL Server
 * generated it). The column stays NOT NULL. The append-only trigger on
 * case_transitions is not touched.
 *
 * Forward-only: down() does nothing on purpose. Putting a getdate() default
 * back would bring the bug back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        $constraints = DB::select("
            SELECT dc.name
            FROM sys.default_constraints dc
            JOIN sys.columns c ON c.object_id = dc.parent_object_id AND c.column_id = dc.parent_column_id
            WHERE dc.parent_object_id = OBJECT_ID(?)
              AND c.name = ?
              AND LOWER(dc.definition) LIKE '%getdate()%'
        ", ['case_transitions', 'created_at']);

        foreach ($constraints as $constraint) {
            DB::statement('ALTER TABLE '.DB::getQueryGrammar()->wrapTable('case_transitions').' DROP CONSTRAINT '.DB::getQueryGrammar()->wrap($constraint->name));
        }
    }

    public function down(): void
    {
        // Forward-only (see above).
    }
};
