<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: the request content, 1:1 with cases. Every column is nullable
 * because a DRAFT is saved incomplete; required-at-submit rules are application
 * rules (slice 8). Derived values are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->unique()->constrained('cases')->noActionOnDelete();
            $table->string('vendor_reference', 64)->nullable()->index(); // not unique
            $table->date('request_date')->nullable();
            $table->string('need_classification', 16)->nullable();      // VITAL | ESSENTIAL | OTHER
            $table->string('item_description', 500)->nullable();
            $table->string('din', 32)->nullable();
            $table->string('item_code', 64)->nullable();
            $table->string('unit_of_measure', 32)->nullable();
            $table->text('purpose')->nullable();
            $table->decimal('quantity_requested', 14, 2)->nullable();
            $table->decimal('average_monthly_consumption', 14, 2)->nullable();
            $table->decimal('central_stores_stock', 14, 2)->nullable();
            $table->decimal('rha_stock', 14, 2)->nullable();
            $table->date('stock_verified_on')->nullable();
            $table->date('stockout_date')->nullable();
            $table->text('urgency_explanation')->nullable();
            $table->string('tender_reference', 120)->nullable();
            $table->string('tender_outcome', 32)->nullable();           // NOT_AWARDED | PARTIALLY_AWARDED | SUPPLIER_UNABLE_TO_SUPPLY
            $table->text('tender_issue_explanation')->nullable();
            $table->date('rfq_date')->nullable();
            $table->string('rfq_audience', 24)->nullable();             // ALL_REGISTERED | SPECIFIED
            $table->text('sample_evaluation_summary')->nullable();
            $table->text('applicant_comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_requisitions');
    }
};
