<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\FailedJobView;
use App\Models\User;
use App\Support\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * IT-ADMIN Part 3: GET /admin, the IT health page (the System Admin's
 * console home). admin.access and system.health.view, else 404
 * (EnsureAdminConsole). Every card links to the list behind its number.
 * Numbers come from SystemHealth (aggregates, cached 60 seconds); no case
 * content or vendor business data is read or sent.
 */
class SystemHealthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $health = SystemHealth::snapshot();
        $since = CarbonImmutable::now(SystemHealth::TIMEZONE);
        $day = $since->subDay()->format('Y-m-d');
        $week = $since->subDays(7)->format('Y-m-d');
        $audit = fn (string $action, string $from) => route('admin.audit.index', ['action' => $action, 'from' => $from]);
        $users = fn (array $q) => route('admin.users.index', $q);

        $u = $health['users'];
        $s = $health['security'];
        $o = $health['operations'];

        return Inertia::render('admin/health', [
            'generated_at' => CarbonImmutable::parse($health['generated_at'])->setTimezone(SystemHealth::TIMEZONE)->format('j M Y, g:i a'),
            'users' => [
                self::card('active_7d', 'Signed in, last 7 days', $u['active_7d'], $users(['status' => User::STATUS_ACTIVE])),
                self::card('pending_activation', 'Pending activation', $u['pending_activation'], $users(['status' => User::STATUS_INVITED])),
                self::card('mfa_not_enrolled', 'Active, MFA not set up', $u['mfa_not_enrolled'], $users(['status' => User::STATUS_ACTIVE, 'mfa' => 'no']), $u['mfa_not_enrolled'] > 0 ? 'warning' : 'neutral'),
                self::card('locked', 'Locked', $u['locked'], $users(['status' => 'locked']), $u['locked'] > 0 ? 'warning' : 'neutral'),
                self::card('disabled', 'Disabled', $u['disabled'], $users(['status' => User::STATUS_DISABLED])),
            ],
            'security' => [
                self::trendCard('failed_logins', 'Failed sign-ins', $s['failed_logins'], $audit('auth.login.failed', $day)),
                self::trendCard('mfa_failures', 'MFA code failures', $s['mfa_failures'], $audit('mfa.challenge_failed', $day)),
                self::trendCard('lockouts', 'Lockouts', $s['lockouts'], $audit('auth.account.locked', $day)),
                self::trendCard('access_denied', 'Access denied', $s['access_denied'], $audit('access.denied', $day)),
            ],
            'operations' => [
                self::card('failed_jobs', 'Failed queue jobs', $o['failed_jobs'], route('admin.jobs.index'), $o['failed_jobs'] > 0 ? 'error' : 'neutral'),
                self::card('pending_jobs', 'Jobs waiting in the queue', $o['pending_jobs'], null),
                self::card('email_failures', 'Email send failures, 7 days', $o['email_failures'], $audit('mail.send_failed', $week), $o['email_failures'] > 0 ? 'warning' : 'neutral'),
                self::card('uploads_infected', 'Uploads infected, 7 days', $o['uploads_infected'], $audit('document.scan_infected', $week), $o['uploads_infected'] > 0 ? 'error' : 'neutral'),
                self::card('uploads_rejected', 'Uploads rejected, 7 days', $o['uploads_rejected'], $audit('document.validation_rejected', $week)),
                self::card('scan_errors', 'Scan errors, 7 days', $o['scan_errors'], $audit('document.scan_error', $week), $o['scan_errors'] > 0 ? 'warning' : 'neutral'),
            ],
            'clamav' => [
                ...$o['clamav'],
                'tone' => match ($o['clamav']['status']) {
                    'reachable' => 'success',
                    'unreachable' => 'error',
                    default => 'neutral',
                },
            ],
            'disk' => [
                'free_gb' => $o['disk']['free_gb'],
                'total_gb' => $o['disk']['total_bytes'] === null ? null : round($o['disk']['total_bytes'] / 1024 ** 3, 1),
                'warn_below_gb' => $o['disk']['warn_below_gb'],
                'warning' => $o['disk']['warning'],
                'tone' => $o['disk']['warning'] ? 'warning' : 'success',
            ],
            'audit_seal' => [...$health['audit_seal'], 'tone' => 'neutral'],
            'failed_logins_by_day' => $health['failed_logins_by_day'],
            // The newest failed jobs (live, not cached), each with its retry action.
            'failed_jobs' => DB::table(SystemHealth::failedJobsTable())
                ->orderByDesc('failed_at')->orderByDesc('id')
                ->limit((int) config('admin.health.failed_jobs_shown', 10))
                ->get()
                ->map(fn ($job) => FailedJobView::row($job))
                ->all(),
            'abilities' => [
                'retry_jobs' => $request->user()->can(Permission::JobsRetry->value),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function card(string $key, string $label, ?int $value, ?string $href, string $tone = 'neutral'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'href' => $href, 'tone' => $tone];
    }

    /** A 24-hour count with the daily average of the 7 days before. */
    private static function trendCard(string $key, string $label, array $metric, string $href): array
    {
        $now = $metric['last_24h'];
        $avg = $metric['prior_7d_daily_average'];

        return [
            ...self::card($key, $label, $now, $href, $now > max(1, $avg * 2) ? 'warning' : 'neutral'),
            'comparison' => $avg,
            'comparison_label' => 'Daily average, previous 7 days',
            'trend' => $now > $avg ? 'up' : ($now < $avg ? 'down' : 'flat'),
        ];
    }
}
