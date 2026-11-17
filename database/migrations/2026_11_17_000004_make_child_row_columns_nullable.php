<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice F1: a draft saves partial rows, so the NOT NULL child-row columns that
 * the vendor types become nullable. Completeness is checked at submit
 * (SubmissionReadiness), not by the database.
 *
 * quantity_delivered keeps its DEFAULT 0 (an omitted value still reads as 0)
 * but accepts NULL when the vendor clears it.
 *
 * Columns set by the system or with defaults the vendor never clears (sort_order,
 * currency, previous_price_maintained, is_recommended, technical_status) stay
 * NOT NULL. documents.withdrawn_reason is already nullable (210000).
 *
 * Forward-only: down() does nothing. Restoring NOT NULL would fail on any
 * partial draft row, and must not be done by deleting rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_quotations', function (Blueprint $table) {
            $table->string('supplier_name', 200)->nullable()->change();
            $table->decimal('unit_price', 14, 4)->nullable()->change();
            $table->decimal('quantity_offered', 14, 2)->nullable()->change();
        });

        Schema::table('case_tender_awards', function (Blueprint $table) {
            $table->string('supplier_name', 200)->nullable()->change();
            $table->decimal('quantity_awarded', 14, 2)->nullable()->change();
            $table->decimal('unit_cost', 14, 4)->nullable()->change();
        });

        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->string('supplier_name', 200)->nullable()->change();
            $table->string('po_number', 64)->nullable()->change();
            $table->decimal('quantity_ordered', 14, 2)->nullable()->change();
            $table->decimal('quantity_delivered', 14, 2)->nullable()->default(0)->change();
        });

        Schema::table('case_stock_expiries', function (Blueprint $table) {
            $table->decimal('quantity', 14, 2)->nullable()->change();
            $table->date('expiry_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Forward-only (see above).
    }
};
