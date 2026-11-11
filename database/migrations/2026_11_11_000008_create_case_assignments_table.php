<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 8.6, 9.2: the role grants the ability, the assignment grants access to the case.
 * Powers the "waiting on me" inboxes. user_id may be null for a pooled
 * assignment (e.g. any Technocrat in a unit; see Q7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->constrained('cases');
            $table->string('role', 100);
            $table->string('purpose', 30)->nullable();       // technical | funding | legal | ps | ...
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('assigned_by')->nullable()->constrained('users');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('reassigned_from_id')->nullable()->constrained('case_assignments');
            $table->timestamps();

            $table->index(['user_id', 'completed_at']);
            $table->index(['role', 'completed_at']);
            $table->index(['case_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_assignments');
    }
};
