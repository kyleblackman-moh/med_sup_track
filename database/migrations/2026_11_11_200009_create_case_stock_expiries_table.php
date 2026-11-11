<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: stock on hand by expiry date. Coverage is derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_stock_expiries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->index()->constrained('cases')->noActionOnDelete();
            $table->decimal('quantity', 14, 2);
            $table->date('expiry_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_stock_expiries');
    }
};
