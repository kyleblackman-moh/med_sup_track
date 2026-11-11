<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 10: per-category required-document checklist and upload limits,
 * held as data because the list is still a business input (Q9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 20)->nullable();      // pharm | non_pharm | null = both
            $table->boolean('required_for_submission')->default(false);
            $table->string('allowed_extensions')->nullable(); // e.g. "pdf,docx,xlsx"
            $table->unsignedInteger('max_size_kb')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
