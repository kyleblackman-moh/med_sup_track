<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 5.5, 13.6: generated non-objection letters with a public verification token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('case_id')->constrained('cases');
            $table->foreignId('template_id')->constrained('letter_templates');
            $table->string('disk', 50)->default('private');
            $table->string('path', 500)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('verification_token', 64)->unique();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->foreignId('acting_for_id')->nullable()->constrained('users');
            $table->timestamp('hardcopy_dispatched_at')->nullable();
            $table->foreignId('hardcopy_dispatched_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index('case_id');
            $table->index(['issued_at', 'hardcopy_dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letters');
    }
};
