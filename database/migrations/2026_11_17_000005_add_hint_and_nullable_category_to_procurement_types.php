<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice F1.
 *
 * - procurement_type_documents.hint: short guidance shown in a drop zone. It
 *   enforces nothing; which files go where is an open question for the CMO's office.
 * - procurement_types.category becomes nullable: AD_HOC has no Pharm / Non-Pharm
 *   category and nothing reads it for that type. The old types keep their values.
 *
 * Forward-only for category: down() leaves it nullable, since restoring NOT NULL
 * would fail once AD_HOC exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_type_documents', function (Blueprint $table) {
            $table->string('hint', 255)->nullable();
        });

        Schema::table('procurement_types', function (Blueprint $table) {
            $table->string('category', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_type_documents', function (Blueprint $table) {
            $table->dropColumn('hint');
        });
    }
};
