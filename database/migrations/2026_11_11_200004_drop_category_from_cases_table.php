<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: procurement_types.category replaces cases.category.
 *
 * The only constraint on the column was the index cases_category_status_index
 * (no default or CHECK constraint), so that is dropped first, explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropIndex('cases_category_status_index');
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * Restores the original definition from 000006: string(20) NOT NULL plus the
     * (category, status) index. Like the original, it only works on an empty table.
     */
    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('category', 20);                 // pharm | non_pharm
            $table->index(['category', 'status']);
        });
    }
};
