<?php

namespace App\Support\Preflight;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * FIX-2 (5): everything app:preflight reads from outside the configuration:
 * the file system, php.ini, ClamAV, the database. READ-ONLY: no method writes
 * anything. Tests replace this class with a fake.
 */
class SystemProbe
{
    public function isWritable(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    public function realpath(string $path): ?string
    {
        $real = realpath($path);

        return $real === false ? null : $real;
    }

    public function fileExists(string $path): bool
    {
        return is_file($path);
    }

    public function ini(string $key): string|false
    {
        return ini_get($key);
    }

    /** clamd answers "PONG" to "zPING". False on any failure; never throws. */
    public function clamdPing(string $host, int $port, int $timeout): bool
    {
        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, max(1, $timeout));
            if ($socket === false) {
                return false;
            }
            stream_set_timeout($socket, max(1, $timeout));
            fwrite($socket, "zPING\0");
            $reply = (string) fread($socket, 64);
            fclose($socket);

            return trim($reply, "\0\r\n ") === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    /** Null when the database answers; otherwise the exception class (never its message, which can name the server). */
    public function databaseError(): ?string
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1 AS ok');

            return null;
        } catch (Throwable $e) {
            return $e::class;
        }
    }

    /** Migration files not yet Ran, or null when the migrations table cannot be read. @return list<string>|null */
    public function pendingMigrations(): ?array
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return null;
            }
            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $ran = $migrator->getRepository()->getRan();

            return array_values(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }

    /** The values of $column in $table, or null when the table cannot be read. @return list<string>|null */
    public function columnValues(string $table, string $column): ?array
    {
        try {
            return DB::table($table)->pluck($column)->map(fn ($v) => (string) $v)->all();
        } catch (Throwable) {
            return null;
        }
    }

    /** Row count, or null when the table cannot be read. */
    public function rowCount(string $table): ?int
    {
        try {
            return DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** Minutes the oldest waiting job has waited (database queue), null when there are none or it cannot be read. */
    public function oldestWaitingJobMinutes(string $table): ?int
    {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }
            $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');

            return $oldest === null ? null : (int) floor(max(0, time() - (int) $oldest) / 60);
        } catch (Throwable) {
            return null;
        }
    }

    public function configCached(): bool
    {
        return app()->configurationIsCached();
    }

    public function routesCached(): bool
    {
        return app()->routesAreCached();
    }
}
