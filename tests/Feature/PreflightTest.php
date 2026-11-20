<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Support\Preflight\Preflight;
use App\Support\Preflight\SystemProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// FIX-2 (5): app:preflight. The pass and fail logic runs against a fake
// SystemProbe and a production-like configuration; one run uses the real
// probe to prove the command never writes. DatabaseTransactions only.

/** A SystemProbe whose answers the test sets. Every default is the good answer. */
function preflightProbe(array $overrides = []): SystemProbe
{
    $probe = new class extends SystemProbe
    {
        public array $answers = [];

        public function isWritable(string $path): bool
        {
            $key = match ($path) {
                storage_path() => 'storage',
                base_path('bootstrap/cache') => 'bootstrap',
                default => $path,
            };

            return $this->answers['writable'][$key] ?? true;
        }

        public function realpath(string $path): ?string
        {
            if (! array_key_exists('realpath', $this->answers) || $path !== config('filesystems.disks.documents.root')) {
                return $path;
            }

            // "PUBLIC/..." stands for a folder under public_path() (datasets load before the app boots).
            $answer = $this->answers['realpath'];

            return is_string($answer) && str_starts_with($answer, 'PUBLIC/') ? public_path(substr($answer, 7)) : $answer;
        }

        public function fileExists(string $path): bool
        {
            return $this->answers['web_config'] ?? true;
        }

        public function ini(string $key): string|false
        {
            return $this->answers['ini'][$key] ?? ['upload_max_filesize' => '25M', 'post_max_size' => '26M', 'expose_php' => 'Off'][$key] ?? false;
        }

        public function clamdPing(string $host, int $port, int $timeout): bool
        {
            return $this->answers['clamd'] ?? true;
        }

        public function databaseError(): ?string
        {
            return $this->answers['db_error'] ?? null;
        }

        public function pendingMigrations(): ?array
        {
            return array_key_exists('pending', $this->answers) ? $this->answers['pending'] : [];
        }

        public function columnValues(string $table, string $column): ?array
        {
            return $this->answers['values'][$table] ?? match ($table) {
                'roles' => array_map(fn (Role $r) => $r->value, Role::cases()),
                'permissions' => Permission::values(),
                default => [],
            };
        }

        public function rowCount(string $table): ?int
        {
            return $this->answers['rows'][$table] ?? 5;
        }

        public function oldestWaitingJobMinutes(string $table): ?int
        {
            return $this->answers['stale'] ?? null;
        }

        public function configCached(): bool
        {
            return $this->answers['cached'] ?? true;
        }

        public function routesCached(): bool
        {
            return $this->answers['cached'] ?? true;
        }
    };
    $probe->answers = $overrides;

    return $probe;
}

/** A production-ready configuration. */
function preflightProductionConfig(array $overrides = []): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.url' => 'https://nol.example.test',
        'mail.default' => 'smtp',
        'queue.default' => 'database',
        'session.secure' => true,
        'documents.scanner' => 'clamd',
        'filesystems.disks.documents.root' => 'D:\\medproc-private\\documents',
        ...$overrides,
    ]);
}

/** Check name => status. @return array<string, string> */
function preflightStatuses(array $probe = [], array $config = []): array
{
    preflightProductionConfig($config);

    return array_column((new Preflight(preflightProbe($probe)))->run(), 'status', 'check');
}

it('passes a production-ready installation, warning only that the worker cannot be confirmed', function () {
    $statuses = preflightStatuses();

    expect(array_keys(array_filter($statuses, fn ($s) => $s !== Preflight::PASS)))->toBe(['Queue worker'])
        ->and($statuses['Queue worker'])->toBe(Preflight::WARN)
        ->and(count($statuses))->toBe(18);
});

it('exits 0 with no FAIL and prints a line per check', function () {
    preflightProductionConfig();
    $this->app->instance(SystemProbe::class, preflightProbe());

    $exit = Artisan::call('app:preflight');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(substr_count($output, 'PASS'))->toBe(17)
        ->and($output)->toContain('17 passed, 1 warnings, 0 failed');
});

it('fails the matching check and exits 1', function (array $probe, array $config, string $check) {
    expect(preflightStatuses($probe, $config)[$check])->toBe(Preflight::FAIL);

    $this->app->instance(SystemProbe::class, preflightProbe($probe));
    preflightProductionConfig($config);
    expect(Artisan::call('app:preflight'))->toBe(1);
})->with([
    'debug on in production' => [[], ['app.debug' => true], 'APP_ENV and APP_DEBUG'],
    'APP_URL localhost' => [[], ['app.url' => 'http://localhost'], 'APP_URL'],
    'APP_URL 127.0.0.1' => [[], ['app.url' => 'https://127.0.0.1:8443'], 'APP_URL'],
    'https without a secure cookie' => [[], ['session.secure' => false], 'SESSION_SECURE_COOKIE'],
    'storage not writable' => [['writable' => ['storage' => false]], [], 'Storage writable'],
    'bootstrap/cache not writable' => [['writable' => ['bootstrap' => false]], [], 'Bootstrap cache writable'],
    'uploads inside public' => [['realpath' => 'PUBLIC/uploads'], [], 'Private upload path'],
    'ClamAV not answering' => [['clamd' => false], [], 'ClamAV'],
    'dev scanner in production' => [[], ['documents.scanner' => 'eicar_dev'], 'ClamAV'],
    'database not reachable' => [['db_error' => 'PDOException'], [], 'Database'],
    'a migration not Ran' => [['pending' => ['2026_12_01_000001_add_thing']], [], 'Migrations'],
    'migrations table unreadable' => [['pending' => null], [], 'Migrations'],
    'a role missing' => [['values' => ['roles' => ['cmo']]], [], 'Seed data'],
    'a permission missing' => [['values' => ['permissions' => ['case.submit']]], [], 'Seed data'],
    'no procurement types' => [['rows' => ['procurement_types' => 0]], [], 'Seed data'],
    'no query routes' => [['rows' => ['query_routes' => 0]], [], 'Seed data'],
    'upload_max_filesize 2M' => [['ini' => ['upload_max_filesize' => '2M']], [], 'php.ini upload_max_filesize'],
    'post_max_size 25M' => [['ini' => ['post_max_size' => '25M']], [], 'php.ini post_max_size'],
    'expose_php On' => [['ini' => ['expose_php' => '1']], [], 'php.ini expose_php'],
    'web.config missing' => [['web_config' => false], [], 'public/web.config'],
]);

it('warns without failing', function (array $probe, array $config, string $check) {
    $statuses = preflightStatuses($probe, $config);

    expect($statuses[$check])->toBe(Preflight::WARN)
        ->and(Preflight::hasFailure(array_map(fn ($s) => ['status' => $s], array_values($statuses))))->toBeFalse();
})->with([
    'MAIL_MAILER log' => [[], ['mail.default' => 'log'], 'MAIL_MAILER'],
    'QUEUE_CONNECTION sync' => [[], ['queue.default' => 'sync'], 'QUEUE_CONNECTION'],
    'caches not built in production' => [['cached' => false], [], 'Config and route cache'],
    'a job waiting 30 minutes' => [['stale' => 30], [], 'Queue worker'],
    'debug on outside production' => [[], ['app.env' => 'staging', 'app.debug' => true], 'APP_ENV and APP_DEBUG'],
    'upload folder not created yet' => [['realpath' => null], [], 'Private upload path'],
]);

it('accepts post_max_size 0 (no limit) and sizes in K, M and G', function () {
    expect(preflightStatuses(['ini' => ['post_max_size' => '0']])['php.ini post_max_size'])->toBe(Preflight::PASS)
        ->and(preflightStatuses(['ini' => ['upload_max_filesize' => '1G']])['php.ini upload_max_filesize'])->toBe(Preflight::PASS)
        ->and(Preflight::bytes('26M'))->toBe(26 * 1024 * 1024)
        ->and(Preflight::bytes('25600K'))->toBe(25 * 1024 * 1024)
        ->and(Preflight::bytes('lots'))->toBeNull();
});

it('never prints a secret or a full credential', function () {
    preflightProductionConfig([
        'app.url' => 'https://admin:UrlPass-77@nol.example.test/path?token=QueryTok-55',
        'app.key' => 'base64:AppKeySecret-11',
        'mail.mailers.smtp.password' => 'MailSecret-22',
        'database.connections.sqlsrv.password' => 'DbSecret-33',
        'database.connections.sqlsrv.username' => 'DbUser-44',
    ]);
    $this->app->instance(SystemProbe::class, preflightProbe(['db_error' => 'PDOException']));

    Artisan::call('app:preflight');
    $output = Artisan::output();

    foreach (['UrlPass-77', 'admin:', 'QueryTok-55', 'AppKeySecret-11', 'MailSecret-22', 'DbSecret-33', 'DbUser-44', '/path'] as $secret) {
        expect($output)->not->toContain($secret);
    }
    expect($output)->toContain('https://nol.example.test');
});

it('never changes data: the real probe runs only reads', function () {
    // The real SystemProbe, with clamd pointed at a closed port so the run is quick.
    config(['documents.scanner' => 'clamd', 'documents.clamd.host' => '127.0.0.1', 'documents.clamd.port' => 1, 'documents.clamd.connect_timeout' => 1]);
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower(ltrim($query->sql));
    });

    Artisan::call('app:preflight');

    $writes = array_filter($statements, fn (string $sql) => preg_match('/^(insert|update|delete|merge|create|alter|drop|truncate|exec)\b/', $sql));
    expect($statements)->not->toBeEmpty()
        ->and(array_values($writes))->toBe([]);
});
