<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TERMINAL-1: withdraw (T15) and final reject (T16).
 *
 * case_transitions.external_message: the optional "message to vendor". The
 * only text of a withdrawal or rejection a vendor ever sees; the internal
 * reason stays in case_transitions.reason. Written once, on the insert: the
 * append-only trigger (trg_case_transitions_append_only) is table-level and is
 * not touched, so it keeps blocking UPDATE and DELETE on these columns too.
 *
 * case_transitions.on_ps_instruction: T16's required "rejected on the
 * instruction of the Permanent Secretary" flag. NULL on every other row.
 *
 * queries.cancel_reason: why a query was cancelled (system text such as
 * "Case withdrawn"; never user-typed). Beside the existing cancelled_at and
 * cancelled_by.
 *
 * Forward-only in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_transitions', function (Blueprint $table) {
            $table->text('external_message')->nullable();
            $table->boolean('on_ps_instruction')->nullable();
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->string('cancel_reason', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropColumn('cancel_reason');
        });

        Schema::table('case_transitions', function (Blueprint $table) {
            $table->dropColumn(['external_message', 'on_ps_instruction']);
        });
    }
};
