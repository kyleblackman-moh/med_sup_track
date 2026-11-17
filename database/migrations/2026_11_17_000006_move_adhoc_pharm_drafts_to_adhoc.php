<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slice F1: the single Ad Hoc form replaces the Pharm / Non-Pharm split.
 *
 * Moves DRAFT cases of type AD_HOC_PHARM to AD_HOC. Nothing else changes:
 * submitted cases keep their type, AD_HOC_NON_PHARM drafts are left alone
 * (there are none in dev data), and no request content or document is touched.
 * The old requisition columns and documents stay as they are.
 *
 * AD_HOC is created here (minimal row) if the seeder has not run yet; the
 * ProcurementTypeSeeder then sets its name, order and document config by code.
 *
 * Guarded and idempotent: only status = DRAFT rows of AD_HOC_PHARM are updated,
 * so a second run finds nothing. Forward-only: down() does nothing (moving a
 * draft back would undo a vendor's work on the new form).
 */
return new class extends Migration
{
    public function up(): void
    {
        $from = DB::table('procurement_types')->where('code', 'AD_HOC_PHARM')->value('id');
        if ($from === null) {
            return; // nothing to move (fresh environment)
        }

        DB::transaction(function () use ($from) {
            $to = DB::table('procurement_types')->where('code', 'AD_HOC')->value('id');

            if ($to === null) {
                $now = CarbonImmutable::now('UTC');
                $to = DB::table('procurement_types')->insertGetId([
                    'code' => 'AD_HOC',
                    'name' => 'Ad hoc purchase',
                    'category' => null,
                    'is_active' => true,
                    'sort_order' => 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('cases')
                ->where('procurement_type_id', $from)
                ->where('status', 'DRAFT')
                ->update(['procurement_type_id' => $to]);
        });
    }

    public function down(): void
    {
        // Forward-only (see above).
    }
};
