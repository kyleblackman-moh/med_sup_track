<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 6.2, 6.4, 9.2: the core case entity. One main status plus three parallel
 * gate tracks. No soft deletes (9.3): use WITHDRAWN or retention rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference_no', 30)->nullable();   // assigned on submission (NIP-2026-0142)
            $table->string('file_no', 50)->nullable();        // assigned at T5 (HE:8/2/50-style)
            $table->foreignUlid('org_id')->constrained('organisations');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('submitted_by')->nullable()->constrained('users');

            $table->string('category', 20);                   // pharm | non_pharm
            $table->string('title');
            $table->text('description')->nullable();

            // Main status (CaseStatus enum) and current holder
            $table->string('status', 40)->default('DRAFT');
            $table->string('current_holder_role', 100)->nullable();

            // Parallel tracks feeding the gate (6.4)
            $table->string('technical_track', 20)->nullable(); // pending | favourable | query_open
            $table->timestamp('technical_completed_at')->nullable();
            $table->foreignId('technical_completed_by')->nullable()->constrained('users');

            $table->string('funding_track', 20)->nullable();   // pending | confirmed | query_open
            $table->timestamp('funding_completed_at')->nullable();
            $table->foreignId('funding_completed_by')->nullable()->constrained('users');

            $table->timestamp('hardcopy_received_at')->nullable();
            $table->foreignId('hardcopy_received_by')->nullable()->constrained('users');
            $table->string('hardcopy_receipt_method', 20)->nullable(); // qr | manual

            // Stage milestones (denormalised from case_transitions for fast listing)
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('status_changed_at')->nullable(); // "age in stage" for dashboards
            $table->timestamp('file_prepared_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['org_id', 'status']);
            $table->index(['current_holder_role', 'status']);
            $table->index(['category', 'status']);
            $table->index('submitted_at');
        });

        // Unique only when present (SQL Server treats NULLs as equal in plain unique indexes).
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX cases_reference_no_unique ON cases (reference_no) WHERE reference_no IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX cases_file_no_unique ON cases (file_no) WHERE file_no IS NOT NULL');
        } else {
            Schema::table('cases', function (Blueprint $table) {
                $table->unique('reference_no');
                $table->unique('file_no');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};
