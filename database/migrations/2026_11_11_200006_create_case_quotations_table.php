<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: one row per supplier quotation. Excluded quotations are kept.
 * Totals are derived, never stored. The three technical_status* columns are set
 * by MOH staff only, never by the vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->index()->constrained('cases')->noActionOnDelete();
            $table->smallInteger('sort_order')->default(0);
            $table->string('supplier_name', 200);
            $table->string('manufacturer', 200)->nullable();
            $table->string('brand', 200)->nullable();
            $table->decimal('unit_price', 14, 4);
            $table->char('currency', 3)->default('TTD');
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('quantity_offered', 14, 2);
            $table->smallInteger('lead_time_days')->nullable();
            $table->decimal('minimum_order_quantity', 14, 2)->nullable();
            $table->date('price_valid_until')->nullable();
            $table->decimal('quantity_available_now', 14, 2)->nullable();
            $table->string('previous_price_maintained', 8)->default('UNKNOWN'); // YES | NO | UNKNOWN
            $table->string('reason_accepted_excluded', 500)->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->decimal('recommended_quantity', 14, 2)->nullable();
            // PENDING | SAMPLE_ACCEPTED | PRIOR_APPROVAL_VERIFIED | NO_ACCEPTABLE_SAMPLE_OR_HISTORY | TECHNICALLY_REJECTED
            $table->string('technical_status', 40)->default('PENDING');
            $table->foreignId('technical_status_set_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->dateTime('technical_status_set_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_quotations');
    }
};
