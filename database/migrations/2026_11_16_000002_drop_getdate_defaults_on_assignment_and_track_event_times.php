<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cleanup-1: drop the getdate() defaults on case_assignments.assigned_at and
 * case_track_events.created_at. getdate() is server local time (4 hours behind
 * UTC); the app always writes UTC itself, and the review-round logic compares
 * these two columns, so a row that silently took the default would misorder
 * rounds. Without a default, an insert that forgets the value fails instead.
 *
 * The constraint names are looked up in sys.default_constraints (SQL Server
 * generated them). The columns stay NOT NULL. The append-only trigger on
 * case_track_events is not touched.
 *
 * Forward-only: down() does nothing on purpose. Putting a getdate() default
 * back would bring the bug back.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'case_assignments' => 'assigned_at',
        'case_track_events' => 'created_at',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            $constraints = DB::select("
                SELECT dc.name
                FROM sys.default_constraints dc
                JOIN sys.columns c ON c.object_id = dc.parent_object_id AND c.column_id = dc.parent_column_id
                WHERE dc.parent_object_id = OBJECT_ID(?)
                  AND c.name = ?
                  AND LOWER(dc.definition) LIKE '%getdate()%'
            ", [$table, $column]);

            foreach ($constraints as $constraint) {
                DB::statement('ALTER TABLE '.DB::getQueryGrammar()->wrapTable($table).' DROP CONSTRAINT '.DB::getQueryGrammar()->wrap($constraint->name));
            }
        }
    }

    public function down(): void
    {
        // Forward-only (see above).
    }
};
