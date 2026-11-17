<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice F1: request-level fields of the single Ad Hoc form (adhoc-form-spec).
 *
 * All nullable except no_stock_on_hand (NOT NULL DEFAULT 0, safe on a populated
 * table). There is no separate consumption unit: unit_of_measure is the one
 * unit for quantity, stock and average monthly consumption. Prices use the
 * unit-price precision of case_quotations.unit_price, decimal(14,4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_requisitions', function (Blueprint $table) {
            $table->string('strength', 100)->nullable();
            $table->string('dosage_form', 100)->nullable();
            $table->text('clinical_note')->nullable();
            $table->string('stock_location', 200)->nullable();
            $table->boolean('no_stock_on_hand')->default(false);
            $table->decimal('quantity_to_purchase', 14, 2)->nullable();
            $table->decimal('tender_price', 14, 4)->nullable();
            $table->decimal('previous_price', 14, 4)->nullable();
            $table->boolean('quoted_price_acceptable')->nullable();
            $table->text('price_note')->nullable();
        });
    }

    public function down(): void
    {
        // On SQL Server, dropColumn also drops the default constraint on no_stock_on_hand.
        Schema::table('case_requisitions', function (Blueprint $table) {
            $table->dropColumn([
                'strength', 'dosage_form', 'clinical_note', 'stock_location', 'no_stock_on_hand',
                'quantity_to_purchase', 'tender_price', 'previous_price', 'quoted_price_acceptable', 'price_note',
            ]);
        });
    }
};
