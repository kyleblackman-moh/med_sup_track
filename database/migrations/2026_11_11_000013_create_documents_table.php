<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 10: versioned, scanned, private documents. Invisible to all but the
 * uploader until scan_status = clean. Optionally attached to a query message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('case_id')->constrained('cases');
            $table->foreignUlid('query_message_id')->nullable()->constrained('query_messages');
            $table->string('type', 50);                      // document_types.code
            $table->unsignedInteger('version')->default(1);
            $table->string('original_filename');
            $table->string('disk', 50)->default('private');
            $table->string('path', 500);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 20)->default('pending'); // pending | clean | infected | failed
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_result')->nullable();
            $table->string('visibility', 10)->default('internal'); // internal | external
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('acting_for_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['case_id', 'type', 'version']);
            $table->index('scan_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
