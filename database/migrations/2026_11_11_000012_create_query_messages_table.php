<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 7.3-7.6: case-scoped messages. Via-CMO messages stay hidden from the
 * recipient until forwarded_at is set. NIPDEC only ever sees visibility = external.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('query_id')->constrained('queries');
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('acting_for_id')->nullable()->constrained('users');
            $table->string('author_role', 100);
            $table->string('recipient_role', 100);
            $table->text('body');
            $table->string('visibility', 10)->default('internal'); // internal | external
            $table->boolean('requires_forward')->default(false);
            $table->foreignId('forwarded_by_cmo_id')->nullable()->constrained('users');
            $table->timestamp('forwarded_at')->nullable();
            $table->text('forward_note')->nullable();
            $table->timestamps();

            $table->index(['query_id', 'created_at']);
            $table->index(['requires_forward', 'forwarded_at']); // CMO "forward required" queue
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_messages');
    }
};
