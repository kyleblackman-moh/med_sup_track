<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7, 9.2: queries are first-class records. Open queries are an overlay
 * on the case, not a status. Lifecycle: OPEN -> ANSWERED -> RESOLVED | CANCELLED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('case_id')->constrained('cases');
            $table->string('type', 20);                      // TECHNICAL | LEGAL | PS_TECHNICAL | PS_LEGAL
            $table->string('subject');
            $table->string('raised_by_role', 100);
            $table->foreignId('raised_by_user_id')->constrained('users');
            $table->foreignId('acting_for_id')->nullable()->constrained('users');
            $table->string('raised_to_role', 100);
            $table->boolean('via_cmo')->default(false);      // copied from query_routes at creation
            $table->string('status', 20)->default('OPEN');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['case_id', 'status']);
            $table->index(['raised_to_role', 'status']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queries');
    }
};
