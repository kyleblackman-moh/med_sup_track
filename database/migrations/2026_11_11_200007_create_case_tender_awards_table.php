<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: prior tender awards for the item. Percentages and totals are derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_tender_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->index()->constrained('cases')->noActionOnDelete();
            $table->smallInteger('sort_order')->default(0);
            $table->string('supplier_name', 200);
            $table->string('manufacturer', 200)->nullable();
            $table->string('brand', 200)->nullable();
            $table->decimal('quantity_awarded', 14, 2);
            $table->decimal('unit_cost', 14, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_tender_awards');
    }
};
