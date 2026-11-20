<?php

namespace App\Support\Preflight;

use App\Enums\Permission;
use App\Enums\Role;

/**
 * FIX-2 (5): the go-live checks behind app:preflight. READ-ONLY: it reads the
 * configuration and asks SystemProbe about the rest; it never writes. A
 * detail never carries a secret or a full credential: hosts, names, counts
 * and on/off only.
 */
final class Preflight
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** The upload limits one 25 MB PDF needs (config documents.max_size_kb = 25600). */
    public const MIN_UPLOAD_BYTES = 25 * 1024 * 1024;

    public const MIN_POST_BYTES = 26 * 1024 * 1024;

    /** A queued job waiting longer than this suggests no worker is running. */
    public const STALE_JOB_MINUTES = 10;

    public function __construct(private readonly SystemProbe $probe) {}

    /** @return list<array{status: string, check: string, detail: string}> */
    public function run(): array
    {
        return [
            $this->environment(),
            $this->appUrl(),
            $this->mailer(),
            $this->queueConnection(),
            $this->secureCookie(),
            $this->writable('Storage writable', storage_path()),
            $this->writable('Bootstrap cache writable', base_path('bootstrap/cache')),
            $this->uploadPath(),
            $this->clamav(),
            $this->database(),
            $this->migrations(),
            $this->seedData(),
            $this->iniSize('upload_max_filesize', self::MIN_UPLOAD_BYTES, '25M'),
            $this->iniSize('post_max_size', self::MIN_POST_BYTES, '26M'),
            $this->exposePhp(),
            $this->caches(),
            $this->queueWorker(),
            $this->webConfig(),
        ];
    }

    /** @param  list<array{status: string}>  $results */
    public static function hasFailure(array $results): bool
    {
        return in_array(self::FAIL, array_column($results, 'status'), true);
    }

    private function environment(): array
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $detail = "APP_ENV={$env}, APP_DEBUG=".($debug ? 'true' : 'false');

        return match (true) {
            $debug && $env === 'production' => $this->result(self::FAIL, 'APP_ENV and APP_DEBUG', $detail.': debug must be off in production'),
            $debug => $this->result(self::WARN, 'APP_ENV and APP_DEBUG', $detail.': turn debug off before go-live'),
            $env !== 'production' => $this->result(self::WARN, 'APP_ENV and APP_DEBUG', $detail.': not production'),
            default => $this->result(self::PASS, 'APP_ENV and APP_DEBUG', $detail),
        };
    }

    private function appUrl(): array
    {
        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $shown = $this->urlForDisplay($url);

        return $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost')
            ? $this->result(self::FAIL, 'APP_URL', "{$shown}: must be the site's real address, not localhost")
            : $this->result(self::PASS, 'APP_URL', $shown);
    }

    private function mailer(): array
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, ['log', 'array'], true)
            ? $this->result(self::WARN, 'MAIL_MAILER', "{$mailer}: emails are not sent")
            : $this->result(self::PASS, 'MAIL_MAILER', $mailer);
    }

    private function queueConnection(): array
    {
        $queue = (string) config('queue.default');

        return $queue === 'sync'
            ? $this->result(self::WARN, 'QUEUE_CONNECTION', 'sync: emails and scans run inside the request')
            : $this->result(self::PASS, 'QUEUE_CONNECTION', $queue);
    }

    private function secureCookie(): array
    {
        $https = parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https';
        $secure = config('session.secure') === true;
        $detail = 'SESSION_SECURE_COOKIE='.($secure ? 'true' : 'false');

        return match (true) {
            $https && ! $secure => $this->result(self::FAIL, 'SESSION_SECURE_COOKIE', $detail.': must be true when APP_URL is https'),
            $https => $this->result(self::PASS, 'SESSION_SECURE_COOKIE', $detail),
            default => $this->result(self::WARN, 'SESSION_SECURE_COOKIE', $detail.': APP_URL is not https'),
        };
    }

    private function writable(string $check, string $path): array
    {
        return $this->probe->isWritable($path)
            ? $this->result(self::PASS, $check, 'writable')
            : $this->result(self::FAIL, $check, 'not writable by this account');
    }

    private function uploadPath(): array
    {
        $root = (string) config('filesystems.disks.documents.root');
        $real = $this->probe->realpath($root);
        $public = $this->probe->realpath(public_path()) ?? public_path();

        if ($real === null) {
            return $this->result(self::WARN, 'Private upload path', 'does not exist yet; it is created on the first upload');
        }

        $inside = str_starts_with(strtolower(rtrim($real, '\\/').DIRECTORY_SEPARATOR), strtolower(rtrim($public, '\\/').DIRECTORY_SEPARATOR));

        return $inside
            ? $this->result(self::FAIL, 'Private upload path', 'is inside the public folder')
            : $this->result(self::PASS, 'Private upload path', 'outside the public folder');
    }

    private function clamav(): array
    {
        $scanner = (string) config('documents.scanner');
        if ($scanner !== 'clamd') {
            return $this->result(config('app.env') === 'production' ? self::FAIL : self::WARN, 'ClamAV', "scanner is {$scanner}, not clamd");
        }

        $host = (string) config('documents.clamd.host');
        $port = (int) config('documents.clamd.port');

        return $this->probe->clamdPing($host, $port, (int) config('documents.clamd.connect_timeout', 5))
            ? $this->result(self::PASS, 'ClamAV', "clamd answered at {$host}:{$port}")
            : $this->result(self::FAIL, 'ClamAV', "no answer from clamd at {$host}:{$port}");
    }

    private function database(): array
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        $error = $this->probe->databaseError();

        return $error === null
            ? $this->result(self::PASS, 'Database', "{$driver} reachable")
            : $this->result(self::FAIL, 'Database', "{$driver} not reachable ({$error})");
    }

    private function migrations(): array
    {
        $pending = $this->probe->pendingMigrations();

        return match (true) {
            $pending === null => $this->result(self::FAIL, 'Migrations', 'the migrations table cannot be read'),
            $pending === [] => $this->result(self::PASS, 'Migrations', 'all Ran'),
            default => $this->result(self::FAIL, 'Migrations', count($pending).' not Ran: '.implode(', ', array_slice($pending, 0, 5)).(count($pending) > 5 ? ', ...' : '')),
        };
    }

    private function seedData(): array
    {
        $roles = $this->probe->columnValues('roles', 'name');
        $permissions = $this->probe->columnValues('permissions', 'name');
        $types = $this->probe->rowCount('procurement_types');
        $routes = $this->probe->rowCount('query_routes');

        $missing = [];
        if ($roles === null || $permissions === null) {
            $missing[] = 'roles and permissions cannot be read';
        } else {
            $missingRoles = array_values(array_diff(array_map(fn (Role $r) => $r->value, Role::cases()), $roles));
            $missingPermissions = array_values(array_diff(Permission::values(), $permissions));
            if ($missingRoles !== []) {
                $missing[] = 'roles missing: '.implode(', ', $missingRoles);
            }
            if ($missingPermissions !== []) {
                $missing[] = 'permissions missing: '.implode(', ', $missingPermissions);
            }
        }
        if (($types ?? 0) === 0) {
            $missing[] = 'no procurement_types';
        }
        if (($routes ?? 0) === 0) {
            $missing[] = 'no query_routes';
        }

        return $missing === []
            ? $this->result(self::PASS, 'Seed data', 'roles, permissions, '.$types.' procurement types, '.$routes.' query routes')
            : $this->result(self::FAIL, 'Seed data', implode('; ', $missing));
    }

    private function iniSize(string $key, int $min, string $needed): array
    {
        $value = $this->probe->ini($key);
        $bytes = $value === false ? null : self::bytes($value);
        $check = "php.ini {$key}";

        return match (true) {
            $bytes === null => $this->result(self::FAIL, $check, 'cannot be read'),
            $bytes === 0 && $key === 'post_max_size' => $this->result(self::PASS, $check, '0 (no limit)'),
            $bytes >= $min => $this->result(self::PASS, $check, $value),
            default => $this->result(self::FAIL, $check, "{$value}: needs at least {$needed}"),
        };
    }

    private function exposePhp(): array
    {
        $value = $this->probe->ini('expose_php');
        $on = $value !== false && in_array(strtolower(trim($value)), ['1', 'on', 'true', 'yes'], true);

        return $on
            ? $this->result(self::FAIL, 'php.ini expose_php', 'On: set expose_php = Off')
            : $this->result(self::PASS, 'php.ini expose_php', 'Off');
    }

    private function caches(): array
    {
        $config = $this->probe->configCached();
        $routes = $this->probe->routesCached();
        $detail = 'config '.($config ? 'cached' : 'not cached').', routes '.($routes ? 'cached' : 'not cached');

        return config('app.env') === 'production' && ! ($config && $routes)
            ? $this->result(self::WARN, 'Config and route cache', $detail.': run php artisan optimize after each deploy')
            : $this->result(self::PASS, 'Config and route cache', $detail);
    }

    private function queueWorker(): array
    {
        $connection = (string) config('queue.default');
        if ($connection === 'sync') {
            return $this->result(self::WARN, 'Queue worker', 'no worker with the sync queue');
        }

        $minutes = $connection === 'database'
            ? $this->probe->oldestWaitingJobMinutes((string) config("queue.connections.{$connection}.table", 'jobs'))
            : null;

        return $minutes !== null && $minutes >= self::STALE_JOB_MINUTES
            ? $this->result(self::WARN, 'Queue worker', "a job has waited {$minutes} minutes: the worker is probably not running")
            : $this->result(self::WARN, 'Queue worker', 'no heartbeat is recorded, so a running worker cannot be confirmed; check the worker service');
    }

    private function webConfig(): array
    {
        return $this->probe->fileExists(public_path('web.config'))
            ? $this->result(self::PASS, 'public/web.config', 'present')
            : $this->result(self::FAIL, 'public/web.config', 'missing');
    }

    /** "25M" => bytes, as PHP reads ini sizes. Null when not a size. */
    public static function bytes(string $value): ?int
    {
        $value = trim($value);
        if (! preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $m)) {
            return null;
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
    }

    /** Scheme, host and port only: never a user, password, path or query string. */
    private function urlForDisplay(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url === '' ? '(empty)' : '(not a URL)';
        }

        return ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return array{status: string, check: string, detail: string} */
    private function result(string $status, string $check, string $detail): array
    {
        return ['status' => $status, 'check' => $check, 'detail' => $detail];
    }
}
