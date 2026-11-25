<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kyle, 2026-11-25: optional free text on the request form's rows.
 * - Pack size (award and order rows), e.g. "Boxes of 28 Tablets": the letter
 *   shows the quantity with it ("6,250 Boxes of 28 Tablets").
 * - Delivery note (order rows), e.g. "Variation of tender in progress": the
 *   letter's Delivery Date cell shows it when entered.
 * Neither is required to submit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_tender_awards', function (Blueprint $table) {
            $table->string('pack_size', 100)->nullable();
        });

        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->string('pack_size', 100)->nullable();
            $table->string('delivery_note', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['pack_size', 'delivery_note']);
        });

        Schema::table('case_tender_awards', function (Blueprint $table) {
            $table->dropColumn('pack_size');
        });
    }
};
