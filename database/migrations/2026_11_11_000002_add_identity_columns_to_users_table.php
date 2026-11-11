<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 8.1-8.2, 9.2: two identity paths (SSO staff, local invite-only NIPDEC users),
 * Fortify TOTP columns, lockout and account status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SSO staff have no local password.
            $table->string('password')->nullable()->change();

            // Nullable at DB level so existing rows survive; the app requires it.
            $table->foreignUlid('org_id')->nullable()->after('id')->constrained('organisations');
            $table->string('user_type', 20)->default('external')->after('org_id');    // staff | external
            $table->string('auth_source', 20)->default('local')->after('user_type'); // sso | local
            $table->string('sso_identifier')->nullable()->after('auth_source');      // directory object id / NameID
            $table->string('job_title')->nullable()->after('email');
            $table->string('status', 20)->default('active')->after('job_title');     // invited | active | disabled

            // MFA (Laravel Fortify TOTP + recovery codes)
            $table->boolean('mfa_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // Lockout and login tracking
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // Lifecycle
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users');

            $table->index(['org_id', 'user_type']);
            $table->index('status');
        });

        // Unique only when present (SQL Server treats NULLs as equal in plain unique indexes).
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX users_sso_identifier_unique ON users (sso_identifier) WHERE sso_identifier IS NOT NULL');
        } else {
            Schema::table('users', fn (Blueprint $table) => $table->unique('sso_identifier'));
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_sso_identifier_unique');
            $table->dropIndex(['org_id', 'user_type']);
            $table->dropIndex(['status']);
            $table->dropForeign(['org_id']);
            $table->dropForeign(['invited_by']);
            $table->dropForeign(['disabled_by']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'org_id', 'user_type', 'auth_source', 'sso_identifier', 'job_title', 'status',
                'mfa_enabled', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
                'failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip',
                'invited_by', 'disabled_at', 'disabled_by',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
