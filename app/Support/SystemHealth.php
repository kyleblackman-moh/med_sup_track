<?php

namespace App\Support;

use App\Documents\Scanning\ClamdScanner;
use App\Enums\AuditAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * IT-ADMIN Part 3: the numbers on the IT health page. Aggregate queries only
 * (one per area), cached for admin.health.cache_seconds (60). No case content
 * and no vendor business data: counts of accounts, audit actions, queue rows,
 * and infrastructure checks (ClamAV, disk).
 *
 * Days are America/Port_of_Spain days (UTC-4, no daylight saving).
 */
final class SystemHealth
{
    public const CACHE_KEY = 'admin.it_health.v1';

    public const TIMEZONE = 'America/Port_of_Spain';

    public const CHART_DAYS = 14;

    /** Security metrics: key => audit action. */
    public const SECURITY = [
        'failed_logins' => AuditAction::LoginFailed,
        'mfa_failures' => AuditAction::MfaChallengeFailed,
        'lockouts' => AuditAction::AccountLocked,
        'access_denied' => AuditAction::AccessDenied,
    ];

    /** Operations metrics over 7 days: key => audit action. */
    public const OPERATIONS = [
        'email_failures' => AuditAction::MailSendFailed,
        'uploads_infected' => AuditAction::DocumentScanInfected,
        'uploads_rejected' => AuditAction::DocumentValidationRejected,
        'scan_errors' => AuditAction::DocumentScanError,
    ];

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, max(1, (int) config('admin.health.cache_seconds', 60)), fn () => self::compute());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public static function compute(): array
    {
        $now = CarbonImmutable::now('UTC');

        return [
            'generated_at' => $now->toIso8601ZuluString(),
            'users' => self::users($now),
            'security' => self::security($now),
            'operations' => [
                ...self::operations($now),
                'failed_jobs' => DB::table(self::failedJobsTable())->count(),
                'pending_jobs' => self::pendingJobs(),
                'clamav' => self::clamav(),
                'disk' => self::disk(),
            ],
            'audit_seal' => ['configured' => false, 'label' => 'Not configured'],
            'failed_logins_by_day' => self::failedLoginsByDay($now),
        ];
    }

    /** @return array<string, int> */
    private static function users(CarbonImmutable $now): array
    {
        $row = DB::table('users')->selectRaw(
            'SUM(CASE WHEN status = ? AND last_login_at >= ? THEN 1 ELSE 0 END) AS active_7d,
             SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_activation,
             SUM(CASE WHEN status = ? AND two_factor_confirmed_at IS NULL THEN 1 ELSE 0 END) AS mfa_not_enrolled,
             SUM(CASE WHEN status = ? AND locked_until > ? THEN 1 ELSE 0 END) AS locked,
             SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS disabled',
            [
                User::STATUS_ACTIVE, self::sql($now->subDays(7)),
                User::STATUS_INVITED,
                User::STATUS_ACTIVE,
                User::STATUS_ACTIVE, self::sql($now),
                User::STATUS_DISABLED,
            ],
        )->first();

        return array_map(fn ($v) => (int) $v, (array) $row);
    }

    /**
     * Last 24 hours, and the daily average of the 7 days before them.
     *
     * @return array<string, array{last_24h: int, prior_7d_daily_average: float}>
     */
    private static function security(CarbonImmutable $now): array
    {
        $dayAgo = $now->subDay();
        $rows = DB::table('audit_logs')
            ->selectRaw(
                'action, SUM(CASE WHEN occurred_at >= ? THEN 1 ELSE 0 END) AS last_24h, SUM(CASE WHEN occurred_at < ? THEN 1 ELSE 0 END) AS prior_7d',
                [self::sql($dayAgo), self::sql($dayAgo)],
            )
            ->whereIn('action', array_map(fn (AuditAction $a) => $a->value, self::SECURITY))
            ->where('occurred_at', '>=', self::sql($now->subDays(8)))
            ->groupBy('action')
            ->get()
            ->keyBy('action');

        return array_map(fn (AuditAction $a) => [
            'last_24h' => (int) ($rows[$a->value]->last_24h ?? 0),
            'prior_7d_daily_average' => round(((int) ($rows[$a->value]->prior_7d ?? 0)) / 7, 1),
        ], self::SECURITY);
    }

    /** @return array<string, int> */
    private static function operations(CarbonImmutable $now): array
    {
        $counts = DB::table('audit_logs')
            ->selectRaw('action, COUNT(*) AS total')
            ->whereIn('action', array_map(fn (AuditAction $a) => $a->value, self::OPERATIONS))
            ->where('occurred_at', '>=', self::sql($now->subDays(7)))
            ->groupBy('action')
            ->pluck('total', 'action');

        return array_map(fn (AuditAction $a) => (int) ($counts[$a->value] ?? 0), self::OPERATIONS);
    }

    /** @return list<array{date: string, label: string, count: int}> oldest first, today last */
    private static function failedLoginsByDay(CarbonImmutable $now): array
    {
        $local = $now->setTimezone(self::TIMEZONE);
        $first = $local->startOfDay()->subDays(self::CHART_DAYS - 1);
        // Seconds from UTC (-14400 for Port of Spain). An int written into the SQL, not a
        // binding: SQL Server only matches the SELECT and GROUP BY expressions when they are identical.
        $day = 'CAST(DATEADD(second, '.(int) $local->getOffset().', occurred_at) AS date)';

        $counts = DB::table('audit_logs')
            ->selectRaw("{$day} AS day, COUNT(*) AS total")
            ->where('action', AuditAction::LoginFailed->value)
            ->where('occurred_at', '>=', self::sql($first->utc()))
            ->groupByRaw($day)
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (int) $total]);

        return array_map(function (int $i) use ($first, $counts) {
            $day = $first->addDays($i);

            return ['date' => $day->format('Y-m-d'), 'label' => $day->format('j M'), 'count' => $counts[$day->format('Y-m-d')] ?? 0];
        }, range(0, self::CHART_DAYS - 1));
    }

    private static function pendingJobs(): ?int
    {
        try {
            return (int) Queue::connection()->size();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{status: string, label: string, host: string|null} */
    private static function clamav(): array
    {
        if (config('documents.scanner') !== 'clamd') {
            return ['status' => 'not_used', 'label' => 'Not used (development scanner)', 'host' => null];
        }

        $host = (string) config('documents.clamd.host');
        $port = (int) config('documents.clamd.port');
        $up = (new ClamdScanner($host, $port))->ping((int) config('admin.health.clamd_timeout_seconds', 2));

        return ['status' => $up ? 'reachable' : 'unreachable', 'label' => $up ? 'Reachable' : 'Not reachable', 'host' => "{$host}:{$port}"];
    }

    /** @return array{free_bytes: int|null, total_bytes: int|null, free_gb: float|null, warn_below_gb: float, warning: bool} */
    private static function disk(): array
    {
        $path = (string) config('documents.root');
        while ($path !== '' && ! is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        $free = is_dir($path) ? @disk_free_space($path) : false;
        $total = is_dir($path) ? @disk_total_space($path) : false;
        $threshold = (float) config('admin.health.disk_warn_free_gb', 10);
        $freeGb = $free === false ? null : round($free / 1024 ** 3, 1);

        return [
            'free_bytes' => $free === false ? null : (int) $free,
            'total_bytes' => $total === false ? null : (int) $total,
            'free_gb' => $freeGb,
            'warn_below_gb' => $threshold,
            // Unknown free space is a warning too: the check itself failed.
            'warning' => $freeGb === null || $freeGb < $threshold,
        ];
    }

    public static function failedJobsTable(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    private static function sql(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s.v');
    }
}
