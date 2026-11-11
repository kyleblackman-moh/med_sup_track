<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 17.4: thresholds are nullable at launch and set after 4-6 weeks of data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('stage', 40);                     // CaseStatus value
            $table->string('role', 100)->nullable();
            $table->unsignedInteger('warn_after_hours')->nullable();
            $table->unsignedInteger('escalate_after_hours')->nullable();
            $table->boolean('business_hours_only')->default(true);
            $table->boolean('active')->default(false);
            $table->timestamps();

            $table->unique(['stage', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
