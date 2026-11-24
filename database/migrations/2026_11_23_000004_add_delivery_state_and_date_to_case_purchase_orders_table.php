<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kyle, 2026-11-23: the order row's "Delivery Date / Status" cell becomes two
 * fields, a Delivery Status dropdown (delivery_state) and a Delivery Date
 * (delivery_date). The old delivery_eta and free-text delivery_status columns
 * are kept (dropped fields keep their columns) and no longer written by the
 * request form.
 *
 * Best effort: an old row gets a status only when its text reads cleanly as one
 * of the three, and a delivery date only when it also has a delivered status and
 * its old date is a past date not before the order date. Everything else stays
 * empty for the vendor to fill in.
 */
return new class extends Migration
{
    private const TEXT = [
        'not delivered' => 'NOT_DELIVERED',
        'undelivered' => 'NOT_DELIVERED',
        'partially delivered' => 'PARTIALLY_DELIVERED',
        'partly delivered' => 'PARTIALLY_DELIVERED',
        'part delivered' => 'PARTIALLY_DELIVERED',
        'partial delivery' => 'PARTIALLY_DELIVERED',
        'partial' => 'PARTIALLY_DELIVERED',
        'fully delivered' => 'FULLY_DELIVERED',
        'delivered' => 'FULLY_DELIVERED',
        'delivered in full' => 'FULLY_DELIVERED',
    ];

    public function up(): void
    {
        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->string('delivery_state', 32)->nullable();
            $table->date('delivery_date')->nullable();
        });

        $today = CarbonImmutable::now('America/Port_of_Spain')->toDateString();

        DB::table('case_purchase_orders')
            ->whereNotNull('delivery_status')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($today) {
                foreach ($rows as $row) {
                    $text = preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $row->delivery_status)));
                    $state = self::TEXT[$text] ?? null;
                    if ($state === null) {
                        continue;
                    }

                    $eta = $row->delivery_eta === null ? null : substr((string) $row->delivery_eta, 0, 10);
                    $ordered = $row->ordered_on === null ? null : substr((string) $row->ordered_on, 0, 10);
                    $date = $state !== 'NOT_DELIVERED' && $eta !== null && $eta <= $today && ($ordered === null || $eta >= $ordered)
                        ? $eta
                        : null;

                    DB::table('case_purchase_orders')->where('id', $row->id)
                        ->update(['delivery_state' => $state, 'delivery_date' => $date]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('case_purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_state', 'delivery_date']);
        });
    }
};
