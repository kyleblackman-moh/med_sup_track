<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: purchase orders already placed. Outstanding quantity is derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->index()->constrained('cases')->noActionOnDelete();
            $table->smallInteger('sort_order')->default(0);
            $table->string('supplier_name', 200);
            $table->string('po_number', 64);
            $table->decimal('quantity_ordered', 14, 2);
            $table->date('ordered_on')->nullable();
            $table->decimal('quantity_delivered', 14, 2)->default(0);
            $table->date('delivery_eta')->nullable();
            $table->string('delivery_status', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_purchase_orders');
    }
};
