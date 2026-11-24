<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kyle, 2026-11-24:
 * - Awarded %: no longer calculated; the vendor types it on each award row
 *   (case_tender_awards.percentage_awarded, optional). Existing rows stay
 *   empty: the old figure was a calculation, not something the vendor entered.
 * - Quantity on hand: one quantity the vendor types (case_requisitions.quantity_on_hand)
 *   instead of the total of the stock batches; the batches keep their expiry
 *   dates. A requisition whose case has batches with quantities gets their total,
 *   so nothing already entered is lost. The batch quantity column is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_tender_awards', function (Blueprint $table) {
            $table->decimal('percentage_awarded', 5, 2)->nullable();
        });

        Schema::table('case_requisitions', function (Blueprint $table) {
            $table->decimal('quantity_on_hand', 14, 2)->nullable();
        });

        $totals = DB::table('case_stock_expiries')
            ->whereNotNull('quantity')
            ->groupBy('case_id')
            ->select('case_id', DB::raw('SUM(quantity) AS total'))
            ->pluck('total', 'case_id');

        foreach ($totals as $caseId => $total) {
            DB::table('case_requisitions')->where('case_id', $caseId)->whereNull('quantity_on_hand')
                ->update(['quantity_on_hand' => $total]);
        }
    }

    public function down(): void
    {
        Schema::table('case_requisitions', function (Blueprint $table) {
            $table->dropColumn('quantity_on_hand');
        });

        Schema::table('case_tender_awards', function (Blueprint $table) {
            $table->dropColumn('percentage_awarded');
        });
    }
};
