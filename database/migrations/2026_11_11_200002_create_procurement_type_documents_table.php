<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6b: required-document configuration per procurement type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_type_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_type_id')->constrained('procurement_types')->noActionOnDelete();
            $table->string('purpose', 64);
            $table->string('label', 160);
            $table->boolean('is_required')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['procurement_type_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_type_documents');
    }
};
