<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 6.6, 9.2: every status change. Insert-only (enforced in a later migration).
 * The timeline and stage metrics derive from this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->constrained('cases');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users');      // null = system (e.g. T4 auto)
            $table->foreignId('acting_for_id')->nullable()->constrained('users'); // delegation (8.5)
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['case_id', 'created_at']);
            $table->index(['to_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_transitions');
    }
};
