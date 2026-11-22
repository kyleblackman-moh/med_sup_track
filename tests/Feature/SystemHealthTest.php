<?php

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\AccountActivation;
use App\Support\SystemHealth;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// IT-ADMIN Part 3: the IT health page. System Admin only (404 for every other
// role); aggregate numbers, cached 60 seconds; failed jobs with an audited,
// permission-checked retry. The dev database holds other rows, so number
// tests compare before and after adding known fixtures.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    SystemHealth::forget();
    config(['documents.scanner' => 'eicar_dev']);
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function healthAudit(string $action, CarbonImmutable $at): void
{
    DB::table('audit_logs')->insert([
        'occurred_at' => $at->utc()->format('Y-m-d H:i:s.v'),
        'actor_type' => 'anonymous',
        'action' => $action,
    ]);
}

function failedJobRow(string $displayName = 'App\\Notifications\\MfaSecurityNotice'): string
{
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => $displayName, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 3, 'data' => ['commandName' => $displayName]]),
        'exception' => "RuntimeException: SMTP refused for someone@example.test on case NR-SECRET-1\n#0 stack",
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('shows the IT health page to the System Admin', function () {
    $this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/health', false)
            ->has('users', 5)->has('security', 4)->has('operations', 6)
            ->has('failed_logins_by_day', 14)
            ->where('audit_seal.label', 'Not configured')
            ->where('clamav.status', 'not_used'));
});

it('answers 404 on the IT health page, the jobs list and retry to every other role', function (Role $role) {
    $user = $role === Role::VendorApplicant ? vendorUser(vendorOrg()) : caseUser($role);
    $uuid = failedJobRow();
    confirmAdminPassword();

    $this->actingAs($user)->get(route('admin.dashboard'))->assertNotFound();
    $this->actingAs($user)->get(route('admin.jobs.index'))->assertNotFound();
    $this->actingAs($user)->post(route('admin.jobs.retry', $uuid))->assertNotFound();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
})->with(fn () => array_values(array_filter(Role::cases(), fn (Role $r) => $r !== Role::SystemAdmin)));

it('counts users from the accounts table', function () {
    $before = SystemHealth::compute()['users'];

    caseUser(Role::Cmo, ['last_login_at' => now()->subDays(2)]);
    caseUser(Role::Cmo, ['last_login_at' => now()->subDays(9)]); // not in the last 7 days
    caseUser(Role::Cmo, ['status' => User::STATUS_INVITED]);
    withoutMfa(caseUser(Role::Cmo));
    caseUser(Role::Cmo, ['locked_until' => now()->addMinutes(10)]);
    caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]);

    $after = SystemHealth::compute()['users'];

    expect([
        'active_7d' => $after['active_7d'] - $before['active_7d'],
        'pending_activation' => $after['pending_activation'] - $before['pending_activation'],
        'mfa_not_enrolled' => $after['mfa_not_enrolled'] - $before['mfa_not_enrolled'],
        'locked' => $after['locked'] - $before['locked'],
        'disabled' => $after['disabled'] - $before['disabled'],
    ])->toBe(['active_7d' => 1, 'pending_activation' => 1, 'mfa_not_enrolled' => 1, 'locked' => 1, 'disabled' => 1]);
});

it('counts security events for the last 24 hours with the 7-day daily average', function () {
    $now = CarbonImmutable::now('UTC');
    $before = SystemHealth::compute()['security'];

    foreach (range(1, 3) as $i) {
        healthAudit('auth.login.failed', $now->subHours($i));
    }
    foreach (range(1, 7) as $i) {
        healthAudit('auth.login.failed', $now->subDays(3));
    }
    healthAudit('auth.login.failed', $now->subDays(9)); // outside both windows
    healthAudit('mfa.challenge_failed', $now->subHours(2));
    healthAudit('auth.account.locked', $now->subHours(3));
    healthAudit('auth.account.locked', $now->subHours(4));
    healthAudit('access.denied', $now->subMinutes(5));

    $after = SystemHealth::compute()['security'];
    $delta = fn (string $key) => $after[$key]['last_24h'] - $before[$key]['last_24h'];

    expect($delta('failed_logins'))->toBe(3)
        ->and($delta('mfa_failures'))->toBe(1)
        ->and($delta('lockouts'))->toBe(2)
        ->and($delta('access_denied'))->toBe(1)
        ->and($after['failed_logins']['prior_7d_daily_average'] - $before['failed_logins']['prior_7d_daily_average'])->toEqualWithDelta(1.0, 0.15);
});

it('counts operations: failed and pending jobs, email failures and upload outcomes over 7 days', function () {
    $now = CarbonImmutable::now('UTC');
    $before = SystemHealth::compute()['operations'];

    failedJobRow();
    failedJobRow();
    healthAudit('mail.send_failed', $now->subDays(1));
    healthAudit('mail.send_failed', $now->subDays(6));
    healthAudit('mail.send_failed', $now->subDays(8)); // too old
    healthAudit('document.scan_infected', $now->subDays(2));
    foreach (range(1, 3) as $i) {
        healthAudit('document.validation_rejected', $now->subHours($i));
    }
    healthAudit('document.scan_error', $now->subDays(5));

    $after = SystemHealth::compute()['operations'];

    expect($after['failed_jobs'] - $before['failed_jobs'])->toBe(2)
        ->and($after['email_failures'] - $before['email_failures'])->toBe(2)
        ->and($after['uploads_infected'] - $before['uploads_infected'])->toBe(1)
        ->and($after['uploads_rejected'] - $before['uploads_rejected'])->toBe(3)
        ->and($after['scan_errors'] - $before['scan_errors'])->toBe(1)
        ->and($after['pending_jobs'])->toBeInt();
});

it('charts failed sign-ins per Port of Spain day for the last 14 days', function () {
    $before = collect(SystemHealth::compute()['failed_logins_by_day'])->keyBy('date');
    $today = CarbonImmutable::now('America/Port_of_Spain');

    healthAudit('auth.login.failed', $today->startOfDay()->addHour());
    healthAudit('auth.login.failed', $today->startOfDay()->addHour());
    healthAudit('auth.login.failed', $today->subDays(13)->startOfDay()->addHours(2));
    // 23:30 local yesterday is 03:30 UTC today: it belongs to yesterday.
    healthAudit('auth.login.failed', $today->subDay()->setTime(23, 30));
    healthAudit('auth.login.failed', $today->subDays(14)->startOfDay()->addHour()); // outside the chart

    $after = collect(SystemHealth::compute()['failed_logins_by_day'])->keyBy('date');
    $delta = fn (CarbonImmutable $d) => $after[$d->format('Y-m-d')]['count'] - $before[$d->format('Y-m-d')]['count'];

    expect($after)->toHaveCount(14)
        ->and($after->keys()->last())->toBe($today->format('Y-m-d'))
        ->and($delta($today))->toBe(2)
        ->and($delta($today->subDay()))->toBe(1)
        ->and($delta($today->subDays(13)))->toBe(1)
        ->and($after->has($today->subDays(14)->format('Y-m-d')))->toBeFalse();
});

it('caches the numbers for 60 seconds', function () {
    $first = SystemHealth::snapshot();
    caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]);

    expect(SystemHealth::snapshot()['users']['disabled'])->toBe($first['users']['disabled']);

    $this->travel(61)->seconds();
    expect(SystemHealth::snapshot()['users']['disabled'])->toBe($first['users']['disabled'] + 1);
});

it('links every card to its filtered list', function () {
    $props = pageProps($this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.dashboard'))->assertOk());
    $cards = collect([...$props['users'], ...$props['security'], ...$props['operations']])->keyBy('key');

    expect($cards['pending_activation']['href'])->toBe(route('admin.users.index', ['status' => 'invited']))
        ->and($cards['mfa_not_enrolled']['href'])->toBe(route('admin.users.index', ['status' => 'active', 'mfa' => 'no']))
        ->and($cards['locked']['href'])->toBe(route('admin.users.index', ['status' => 'locked']))
        ->and($cards['failed_logins']['href'])->toContain('action=auth.login.failed')
        ->and($cards['access_denied']['href'])->toContain('action=access.denied')
        ->and($cards['failed_jobs']['href'])->toBe(route('admin.jobs.index'))
        ->and($cards['email_failures']['href'])->toContain('action=mail.send_failed')
        ->and($cards['uploads_infected']['href'])->toContain('action=document.scan_infected');

    foreach (['users', 'security', 'operations'] as $group) {
        foreach ($props[$group] as $card) {
            expect($card)->toHaveKeys(['key', 'label', 'value', 'href', 'tone']);
        }
    }
});

it('sends no case content, vendor data or job internals to the page', function () {
    $case = makeCase(['org_id' => vendorOrg()->id, 'status' => CaseStatus::SUBMITTED, 'submitted_at' => now(), 'reference_no' => 'NR-HEALTH-'.Str::upper(Str::random(6))]);
    failedJobRow();

    $json = json_encode(pageProps($this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.dashboard'))->assertOk()));

    expect($json)->not->toContain($case->reference_no)
        ->not->toContain('NR-SECRET-1')
        ->not->toContain('someone@example.test')
        ->not->toContain('commandName')
        ->and($json)->toContain('RuntimeException');
});

it('reports ClamAV reachable or not, and not used for the development scanner', function () {
    config(['documents.scanner' => 'clamd', 'documents.clamd.host' => '127.0.0.1', 'documents.clamd.port' => 1, 'admin.health.clamd_timeout_seconds' => 1]);
    expect(SystemHealth::compute()['operations']['clamav']['status'])->toBe('unreachable');

    $server = startFakeClamd('', 'ping');
    config(['documents.clamd.port' => $server[0]]);
    $status = SystemHealth::compute()['operations']['clamav']['status'];
    $received = stopFakeClamd($server);

    expect($status)->toBe('reachable')
        ->and($received)->toBe("zPING\0");

    config(['documents.scanner' => 'eicar_dev']);
    expect(SystemHealth::compute()['operations']['clamav']['status'])->toBe('not_used');
});

it('warns when free disk space is under the configured threshold', function () {
    config(['admin.health.disk_warn_free_gb' => 1_000_000]);
    $low = SystemHealth::compute()['operations']['disk'];

    config(['admin.health.disk_warn_free_gb' => 0]);
    $fine = SystemHealth::compute()['operations']['disk'];

    expect($low['warning'])->toBeTrue()
        ->and($low['free_gb'])->toBeFloat()
        ->and($fine['warning'])->toBeFalse();
});

it('lists failed jobs with the job class and exception class only', function () {
    $uuid = failedJobRow();

    $props = pageProps($this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.jobs.index'))->assertOk());
    $row = collect($props['jobs']['data'])->firstWhere('uuid', $uuid);

    expect($row)->toMatchArray(['job' => 'App\\Notifications\\MfaSecurityNotice', 'queue' => 'default', 'exception' => 'RuntimeException'])
        ->and(json_encode($props))->not->toContain('NR-SECRET-1')
        ->and($props['abilities']['retry'])->toBeTrue();
});

it('retries a failed job, audited, after a password confirmation', function () {
    Queue::fake();
    $admin = caseUser(Role::SystemAdmin);
    $uuid = failedJobRow();
    confirmAdminPassword();

    $this->actingAs($admin)->from(route('admin.jobs.index'))->post(route('admin.jobs.retry', $uuid))
        ->assertRedirect(route('admin.jobs.index'))
        ->assertSessionHas('success');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();
    $pushed = Queue::pushedRaw(fn (string $payload, ?string $queue) => $queue === 'default' && json_decode($payload, true)['uuid'] === $uuid);
    expect($pushed)->toHaveCount(1)
        ->and(json_decode($pushed->first()['payload'], true)['attempts'])->toBe(0);

    $row = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'job.retried')->sole();
    expect((int) $row->actor_user_id)->toBe($admin->id)
        ->and($row->subject_id)->toBe($uuid)
        ->and(json_decode($row->metadata, true))->toBe(['uuid' => $uuid, 'job' => 'App\\Notifications\\MfaSecurityNotice', 'queue' => 'default', 'connection' => 'database'])
        ->and($row->request_id)->not->toBeNull();
});

it('refuses a retry without jobs.retry (403), or without a recent password confirmation', function () {
    Queue::fake();
    $uuid = failedJobRow();
    $role = SpatieRole::create(['name' => 'health_viewer_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
    $role->givePermissionTo([Permission::AdminAccess->value, Permission::SystemHealthView->value]);
    $viewer = caseUser($role->name);
    confirmAdminPassword();

    $this->actingAs($viewer)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($viewer)->post(route('admin.jobs.retry', $uuid))->assertForbidden();

    $this->withSession(['auth.password_confirmed_at' => 0]);
    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.jobs.retry', $uuid))->assertRedirect(route('password.confirm'));

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'job.retried')->count())->toBe(0);
});

it('answers 404 to a retry of an unknown job', function () {
    confirmAdminPassword();

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.jobs.retry', (string) Str::uuid()))->assertNotFound();
});

it('records a queued notification that failed for good as mail.send_failed', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => SendQueuedNotifications::class]]);
    $job->shouldReceive('resolveName')->andReturn('App\\Notifications\\MfaSecurityNotice');

    event(new JobFailed('database', $job, new RuntimeException('smtp down for someone@example.test')));
    event(new NotificationFailed(caseUser(Role::Cmo), new AccountActivation('https://x.test', 48), 'mail'));

    $rows = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'mail.send_failed')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and(json_decode($rows[0]->metadata, true))->toBe(['notification' => 'App\\Notifications\\MfaSecurityNotice', 'exception' => RuntimeException::class, 'via' => 'queue'])
        ->and($rows[0]->metadata)->not->toContain('someone@example.test')
        ->and(json_decode($rows[1]->metadata, true))->toBe(['notification' => AccountActivation::class, 'channel' => 'mail', 'via' => 'channel']);
});

it('ignores a failed job that is not an email', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => 'App\\Documents\\Scanning\\ScanDocumentJob']]);

    event(new JobFailed('database', $job, new RuntimeException('x')));

    expect(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'mail.send_failed')->count())->toBe(0);
});
