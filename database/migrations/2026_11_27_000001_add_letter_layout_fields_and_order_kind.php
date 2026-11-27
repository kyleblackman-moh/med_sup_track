<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kyle, 2026-11-27: the request letter's new section order.
 *
 * - case_requisitions: five optional vendor fields the letter prints: Purpose
 *   of Request (section 1), Purpose Summary (section 6 "Purpose" row),
 *   Outstanding Order Note (section 6 lead-in), Basis of Recommendation
 *   (section 5) and RFQ Number (the quotation comparison annex).
 * - case_purchase_orders.order_kind: ORIGINAL_TENDER (orders under the original
 *   tender, letter 3.2) or PREVIOUS_REQUEST (orders of tender excesses already
 *   approved, letter 3.3). Every existing row is ORIGINAL_TENDER.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_requisitions', function (Blueprint $table) {
            $table->string('purpose_of_request', 1000)->nullable();
            $table->string('purpose_summary', 200)->nullable();
            $table->string('outstanding_order_note', 300)->nullable();
            $table->string('basis_of_recommendation', 200)->nullable();
            $table->string('rfq_number', 50)->nullable();
        });

        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->string('order_kind', 20)->default('ORIGINAL_TENDER');
        });

        // The default fills existing rows on SQL Server; set explicitly so it holds on any driver.
        DB::table('case_purchase_orders')->whereNull('order_kind')->update(['order_kind' => 'ORIGINAL_TENDER']);
    }

    public function down(): void
    {
        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->dropColumn('order_kind');
        });

        Schema::table('case_requisitions', function (Blueprint $table) {
            $table->dropColumn(['purpose_of_request', 'purpose_summary', 'outstanding_order_note', 'basis_of_recommendation', 'rfq_number']);
        });
    }
};
