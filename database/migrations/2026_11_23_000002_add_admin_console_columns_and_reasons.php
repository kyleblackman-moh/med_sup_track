<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT-ADMIN.
 *
 * audit_logs.request_id: the id of the HTTP request that wrote the row
 * (App\Http\Middleware\AssignRequestId), NULL for console and queued work.
 * Written once, on the insert; the append-only trigger (INSTEAD OF UPDATE,
 * DELETE) is table-level and keeps covering this column.
 *
 * user_invitations.user_id: the pending account an activation link belongs to.
 * Admin-created accounts exist (status "invited") before the link is used.
 *
 * admin_action_reasons: the reason an administrator typed for a role change,
 * a disable, a re-enable or an MFA reset. User-typed text never goes into an
 * audit payload: the audit row carries reason_id and reason_length only.
 * Insert-only by convention (App\Models\AdminActionReason refuses updates and
 * deletes). No cascades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('request_id', 36)->nullable();
        });

        Schema::table('user_invitations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->index(['user_id', 'accepted_at']);
        });

        Schema::create('admin_action_reasons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('action', 60);
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->foreignId('target_user_id')->nullable()->constrained('users');
            $table->text('reason');
            $table->dateTime('created_at', 3);

            $table->index(['target_user_id', 'created_at']);
        });
    }

    /** DEV ONLY. */
    public function down(): void
    {
        Schema::dropIfExists('admin_action_reasons');

        Schema::table('user_invitations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'accepted_at']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('request_id');
        });
    }
};
