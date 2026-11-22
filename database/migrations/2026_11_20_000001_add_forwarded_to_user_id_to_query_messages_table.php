<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WORKFLOW-2 (Step 2): the officer the CMO named when forwarding a held
 * message. That officer becomes a party to the query (thread and case header
 * only) even when not assigned to the case. Nullable and additive; the
 * foreign key uses the default NO ACTION (no restrictOnDelete on SQL Server).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_messages', function (Blueprint $table) {
            $table->foreignId('forwarded_to_user_id')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('query_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forwarded_to_user_id');
        });
    }
};
