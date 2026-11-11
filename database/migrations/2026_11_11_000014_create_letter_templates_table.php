<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 5.5, FR-29: admin-managed, versioned letter templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);                      // e.g. non_objection
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->longText('body');                        // Blade/HTML source rendered to PDF
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['code', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_templates');
    }
};
