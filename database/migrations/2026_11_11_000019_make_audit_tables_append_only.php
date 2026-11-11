<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 9.3, 13.4: case_transitions and audit_logs are insert-only.
 *
 * Triggers block UPDATE and DELETE for every database user. They do not stop
 * TRUNCATE or DROP, so production must also restrict the application's
 * database login, e.g. on SQL Server:
 *   DENY UPDATE, DELETE, ALTER ON dbo.audit_logs TO [app_user];
 *   DENY UPDATE, DELETE, ALTER ON dbo.case_transitions TO [app_user];
 */
return new class extends Migration
{
    private array $tables = ['audit_logs', 'case_transitions'];

    public function up(): void
    {
        $driver = DB::getDriverName();

        foreach ($this->tables as $table) {
            $message = "{$table} is append-only";

            if ($driver === 'sqlsrv') {
                DB::unprepared("
                    CREATE TRIGGER trg_{$table}_append_only ON {$table}
                    INSTEAD OF UPDATE, DELETE
                    AS
                    BEGIN
                        THROW 51000, '{$message}', 1;
                    END
                ");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                foreach (['update', 'delete'] as $op) {
                    DB::unprepared("
                        CREATE TRIGGER trg_{$table}_no_{$op} BEFORE ".strtoupper($op)." ON {$table}
                        FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'
                    ");
                }
            } elseif ($driver === 'pgsql') {
                DB::unprepared("
                    CREATE OR REPLACE FUNCTION fn_{$table}_append_only() RETURNS trigger AS \$\$
                    BEGIN RAISE EXCEPTION '{$message}'; END;
                    \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER trg_{$table}_append_only BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION fn_{$table}_append_only();
                ");
            } elseif ($driver === 'sqlite') {
                foreach (['update', 'delete'] as $op) {
                    DB::unprepared("
                        CREATE TRIGGER trg_{$table}_no_{$op} BEFORE ".strtoupper($op)." ON {$table}
                        BEGIN SELECT RAISE(ABORT, '{$message}'); END
                    ");
                }
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        foreach ($this->tables as $table) {
            if ($driver === 'sqlsrv') {
                DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_append_only");
            } elseif ($driver === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_append_only ON {$table}");
                DB::unprepared("DROP FUNCTION IF EXISTS fn_{$table}_append_only()");
            } elseif (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
                DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS trg_{$table}_no_delete");
            }
        }
    }
};
