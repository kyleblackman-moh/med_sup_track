<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice V2-1: one adaptive request form for five request types. Additive only.
 *
 * - case_requisitions: the tender cycle dates and the quantity already on order.
 *   "Contract reference" is the existing tender_reference column (label only).
 * - case_tender_awards and case_quotations: country of origin; quotations also
 *   ex stock and remarks.
 * - case_variations: the Tender Variation details, at most one row per case.
 *   NO ACTION on delete (the SQL Server default); CaseEraser deletes the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_requisitions', function (Blueprint $table) {
            $table->date('tender_cycle_start')->nullable();
            $table->date('tender_cycle_end')->nullable();
            $table->decimal('quantity_on_order', 14, 2)->nullable();
        });

        Schema::table('case_tender_awards', function (Blueprint $table) {
            $table->string('country_of_origin', 100)->nullable();
        });

        Schema::table('case_quotations', function (Blueprint $table) {
            $table->boolean('ex_stock')->nullable();
            $table->string('country_of_origin', 100)->nullable();
            $table->string('remarks', 255)->nullable();
        });

        Schema::create('case_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->unique()->constrained('cases')->noActionOnDelete();
            $table->string('variation_type', 100)->nullable();
            $table->string('existing_detail', 500)->nullable();
            $table->string('proposed_detail', 500)->nullable();
            $table->date('effective_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_variations');

        Schema::table('case_quotations', function (Blueprint $table) {
            $table->dropColumn(['ex_stock', 'country_of_origin', 'remarks']);
        });

        Schema::table('case_tender_awards', function (Blueprint $table) {
            $table->dropColumn('country_of_origin');
        });

        Schema::table('case_requisitions', function (Blueprint $table) {
            $table->dropColumn(['tender_cycle_start', 'tender_cycle_end', 'quantity_on_order']);
        });
    }
};
