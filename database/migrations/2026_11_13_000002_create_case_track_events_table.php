<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 10b: every reviewer outcome on a gate track (technical, funding).
 * Insert-only, like case_transitions: the model refuses updates and deletes,
 * and on SQL Server a trigger backs that up. No cascades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_track_events', function (Blueprint $table) {
            $table->id();
            // No ->restrictOnDelete(): SQL Server has no ON DELETE RESTRICT. Its
            // default, NO ACTION, already refuses to delete a referenced row.
            $table->foreignUlid('case_id')->constrained('cases');
            $table->string('track', 20);   // technical | funding
            $table->string('outcome', 20); // favourable | not_favourable | confirmed | not_confirmed
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['case_id', 'track', 'actor_id', 'id']);
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared("
                CREATE TRIGGER trg_case_track_events_append_only ON case_track_events
                INSTEAD OF UPDATE, DELETE
                AS
                BEGIN
                    THROW 51000, 'case_track_events is append-only', 1;
                END
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_case_track_events_append_only');
        }

        Schema::dropIfExists('case_track_events');
    }
};
