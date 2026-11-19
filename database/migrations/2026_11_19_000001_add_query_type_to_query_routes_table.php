<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OVERNIGHT-2 (D1): the query type each route carries (TECHNICAL, LEGAL,
 * PS_TECHNICAL, PS_LEGAL), copied to queries.type when a query is raised.
 * Additive and nullable: every other column the query loops need already
 * exists on queries and query_messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_routes', function (Blueprint $table) {
            $table->string('query_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('query_routes', function (Blueprint $table) {
            $table->dropColumn('query_type');
        });
    }
};
