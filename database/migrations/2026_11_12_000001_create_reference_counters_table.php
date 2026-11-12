<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 8d: one counter row per year for case reference numbers. Incremented
 * under a row lock inside the submit transaction, so numbers are gap-free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_counters', function (Blueprint $table) {
            $table->smallInteger('year')->primary();
            $table->integer('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_counters');
    }
};
