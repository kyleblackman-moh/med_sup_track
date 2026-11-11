<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: procurement types. category (PHARM | NON_PHARM) is the single source
 * of truth for a case's category (cases.category is dropped in 200004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('category', 16);                 // PHARM | NON_PHARM
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_types');
    }
};
