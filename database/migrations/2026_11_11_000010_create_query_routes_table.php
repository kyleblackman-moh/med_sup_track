<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.2: routing rules as data, so the business can change them without a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_routes', function (Blueprint $table) {
            $table->id();
            $table->string('from_role', 100);
            $table->string('to_role', 100);
            $table->boolean('via_cmo')->default(false);
            $table->boolean('active')->default(true);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['from_role', 'to_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_routes');
    }
};
