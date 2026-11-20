<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice V2-5: Details of Variation Requested, one row per variation, shown
 * when the previous-request answer is Yes. Mirrors case_tender_awards: id key,
 * case foreign key with NO ACTION on delete (the SQL Server default; CaseEraser
 * deletes the rows), sort_order, timestamps. Every vendor column is nullable
 * (a draft saves partial rows; completeness is checked at submit).
 *
 * The old single-row case_variations table (slice V2-1) is left untouched;
 * nothing reads it now and its data is not copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_variation_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->index()->constrained('cases')->noActionOnDelete();
            $table->smallInteger('sort_order')->default(0);
            $table->string('variation_type', 100)->nullable();
            $table->string('existing_detail', 500)->nullable();
            $table->string('proposed_detail', 500)->nullable();
            $table->string('effective_detail', 100)->nullable(); // "Effective Date / Application P.O.", free text
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_variation_rows');
    }
};
