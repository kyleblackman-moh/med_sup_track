<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 9b: the calendar date (America/Port_of_Spain) the hardcopy arrived.
 *
 * The existing columns keep their meaning for the record itself:
 * hardcopy_received_at = when it was recorded, hardcopy_received_by = who
 * recorded it (FK to users, no cascade). All three are set and cleared together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->date('hardcopy_received_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('hardcopy_received_on');
        });
    }
};
