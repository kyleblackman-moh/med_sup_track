<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 8c: replaces the unused placeholder documents table from 000013 with the
 * upload/scan/version design. The old table had no rows and nothing referenced
 * it; this migration refuses to run if that is no longer true.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documents') && DB::table('documents')->exists()) {
            throw new RuntimeException('documents has rows; refusing to recreate it.');
        }

        Schema::dropIfExists('documents');

        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('case_id')->constrained('cases')->noActionOnDelete();
            $table->string('purpose', 64);
            $table->unsignedSmallInteger('version')->default(1);
            $table->ulid('supersedes_id')->nullable();            // self FK added below, once the primary key exists
            $table->string('original_name', 255);              // sanitised display name only
            $table->string('storage_key', 255)->unique();      // never derived from the name
            $table->string('mime', 100);                       // detected from content
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 16)->default('PENDING'); // PENDING | CLEAN | INFECTED | ERROR
            $table->unsignedTinyInteger('scan_attempts')->default(0);
            $table->string('scan_detail', 255)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->string('visibility', 16);                  // INTERNAL | EXTERNAL
            $table->foreignId('uploaded_by')->constrained('users')->noActionOnDelete();
            $table->dateTime('uploaded_at');
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->string('withdrawn_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['case_id', 'purpose']);
            $table->index(['case_id', 'scan_status']);
            $table->index('sha256');
        });

        // SQL Server needs the primary key in place before a self-referencing key.
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('supersedes_id')->references('id')->on('documents')->noActionOnDelete();
        });
    }

    /** Restores the original 000013 definition. */
    public function down(): void
    {
        Schema::dropIfExists('documents');

        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('case_id')->constrained('cases');
            $table->foreignUlid('query_message_id')->nullable()->constrained('query_messages');
            $table->string('type', 50);
            $table->unsignedInteger('version')->default(1);
            $table->string('original_filename');
            $table->string('disk', 50)->default('private');
            $table->string('path', 500);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 20)->default('pending');
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_result')->nullable();
            $table->string('visibility', 10)->default('internal');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('acting_for_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['case_id', 'type', 'version']);
            $table->index('scan_status');
        });
    }
};
