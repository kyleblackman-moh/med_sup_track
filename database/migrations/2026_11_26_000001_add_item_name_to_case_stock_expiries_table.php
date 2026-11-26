<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kyle, 2026-11-26: each stock item row names its drug beside its expiry date.
 * Nullable, so rows saved before keep working; the request form asks for it on
 * every entered row (RequestSections).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_stock_expiries', function (Blueprint $table) {
            $table->string('item_name', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('case_stock_expiries', function (Blueprint $table) {
            $table->dropColumn('item_name');
        });
    }
};
