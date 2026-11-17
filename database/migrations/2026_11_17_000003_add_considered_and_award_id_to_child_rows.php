<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice F1.
 *
 * - case_quotations.considered: NOT NULL DEFAULT 1, so existing rows read as
 *   considered. A row with considered = 0 needs reason_accepted_excluded at submit.
 * - case_purchase_orders.award_id: the award row an order was placed against.
 *   Nullable; default NO ACTION (no restrictOnDelete, no cascade). SaveRequisition
 *   nulls the link before it removes an award, so no order is left dangling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_quotations', function (Blueprint $table) {
            $table->boolean('considered')->default(true);
        });

        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->foreignId('award_id')->nullable()->constrained('case_tender_awards')->noActionOnDelete();
            $table->index('award_id');
        });
    }

    public function down(): void
    {
        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['award_id']);
            $table->dropIndex(['award_id']);
            $table->dropColumn('award_id');
        });

        Schema::table('case_quotations', function (Blueprint $table) {
            $table->dropColumn('considered');
        });
    }
};
