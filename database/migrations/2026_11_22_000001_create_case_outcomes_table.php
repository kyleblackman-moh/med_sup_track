<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-09: how a case ended when it was withdrawn (T15) or finally rejected
 * (T16). One row per case, written with the transition, insert-only.
 * vendor_message is the only text the vendor sees (external); the internal
 * reason stays in case_transitions.reason. ps_instructed records the CMO's
 * confirmation that the Permanent Secretary instructed a rejection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('case_id')->unique()->constrained('cases');
            $table->string('kind', 20); // withdrawn | rejected
            $table->text('vendor_message')->nullable();
            $table->boolean('ps_instructed')->default(false);
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_outcomes');
    }
};
