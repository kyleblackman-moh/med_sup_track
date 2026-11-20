<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice V2-5: the request form's first block asks "Was a request previously
 * made for this drug in this current tender cycle?". Null = not answered yet.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->boolean('previous_request_in_cycle')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('previous_request_in_cycle');
        });
    }
};
