<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: every case has a procurement type. NOT NULL because cases held 0 rows
 * when this was written (SQL Server only allows adding a NOT NULL column without
 * a default to an empty table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->foreignId('procurement_type_id')->constrained('procurement_types')->noActionOnDelete();
            $table->index('procurement_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['procurement_type_id']);
            $table->dropIndex(['procurement_type_id']);
            $table->dropColumn('procurement_type_id');
        });
    }
};
