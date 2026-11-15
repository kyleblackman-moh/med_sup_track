<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 10f: the issued non-objection letter, recorded by hand (the letter is
 * prepared and signed outside the system). Columns on cases, because the
 * letters table is for system-generated letters (template and verification
 * token, both NOT NULL) and stays untouched.
 *
 * Two CHECK constraints:
 *   ck_cases_letter_recorded_together  letter_date, letter_recorded_by and
 *                                      letter_recorded_at all set or all null.
 *   ck_cases_letter_details            no date: reference and channel dates
 *                                      null; a date: at least one channel date.
 *
 * Nullable foreign key letter_recorded_by: default NO ACTION, no cascade.
 * Forward-only in practice.
 */
return new class extends Migration
{
    private const RECORDED = 'ck_cases_letter_recorded_together';

    private const DETAILS = 'ck_cases_letter_details';

    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->date('letter_date')->nullable();
            $table->string('letter_reference', 40)->nullable();
            $table->date('letter_emailed_on')->nullable();
            $table->date('letter_hardcopy_sent_on')->nullable();
            $table->foreignId('letter_recorded_by')->nullable()->constrained('users');
            $table->timestamp('letter_recorded_at')->nullable();
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE cases ADD CONSTRAINT '.self::RECORDED.' CHECK ('
                .'(letter_date IS NULL AND letter_recorded_by IS NULL AND letter_recorded_at IS NULL)'
                .' OR (letter_date IS NOT NULL AND letter_recorded_by IS NOT NULL AND letter_recorded_at IS NOT NULL))');

            DB::statement('ALTER TABLE cases ADD CONSTRAINT '.self::DETAILS.' CHECK ('
                .'(letter_date IS NULL AND letter_reference IS NULL AND letter_emailed_on IS NULL AND letter_hardcopy_sent_on IS NULL)'
                .' OR (letter_date IS NOT NULL AND (letter_emailed_on IS NOT NULL OR letter_hardcopy_sent_on IS NOT NULL)))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE cases DROP CONSTRAINT '.self::DETAILS);
            DB::statement('ALTER TABLE cases DROP CONSTRAINT '.self::RECORDED);
        }

        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['letter_recorded_by']);
            $table->dropColumn(['letter_date', 'letter_reference', 'letter_emailed_on', 'letter_hardcopy_sent_on', 'letter_recorded_by', 'letter_recorded_at']);
        });
    }
};
