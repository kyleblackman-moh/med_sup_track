<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 13.4: append-only audit log (enforced in the next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users');      // null = system / anonymous
            $table->foreignId('acting_for_id')->nullable()->constrained('users');
            $table->string('action', 100);                   // e.g. auth.login_failed, case.transitioned
            $table->string('subject_type', 150)->nullable();
            $table->string('subject_id', 36)->nullable();    // ULID, UUID or integer as string
            $table->char('case_id', 26)->nullable();         // no FK: the log must outlive any record
            $table->text('properties')->nullable();          // JSON: before/after, extra context
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('case_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
