<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the first-draft audit_logs table (2026_11_11_000018, which had FKs to
 * users and app-side created_at) with the agreed design. The old table held no
 * rows when this was written. SQL Server only.
 *
 * Append-only is enforced by an INSTEAD OF UPDATE, DELETE trigger that THROWs,
 * because the application login is db_owner, so DENY permissions cannot be relied on.
 * No application code may ever UPDATE or DELETE audit_logs.
 *
 * actor_org_id and case_id are char(26) because organisations and cases use ULID
 * keys. No foreign keys anywhere: audit rows must outlive the rows they mention.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_append_only');
        Schema::dropIfExists('audit_logs');

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at', 3);                       // datetime2(3), UTC, set by the app
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->char('actor_org_id', 26)->nullable();
            $table->string('actor_type', 10);                         // user | system | anonymous
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->char('case_id', 26)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('metadata')->nullable();                     // nvarchar(max), JSON

            $table->index('occurred_at');
            $table->index(['case_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });

        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT ck_audit_logs_metadata_json CHECK (metadata IS NULL OR ISJSON(metadata) = 1)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_logs_append_only ON audit_logs
            INSTEAD OF UPDATE, DELETE
            AS
                THROW 51000, 'audit_logs is append-only', 1;
            SQL);
    }

    /**
     * DEV ONLY. Destroys the audit trail. Never roll this back in production.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_append_only');
        Schema::dropIfExists('audit_logs');
    }
};
