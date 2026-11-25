<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The request letter PDF (Kyle, 2026-11-24):
 * - users.division: the sign-off's division line under the name and job title.
 *   Not captured anywhere yet, so it starts empty and the letter leaves it out.
 * - organisations.logo_path: the letterhead logo, added later. Empty for now;
 *   the letterhead keeps its slot blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('division', 150)->nullable();
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('division');
        });
    }
};
