<?php

use App\Actions\Fortify\AuthenticateUser;
use App\Enums\AuditAction;
use App\Enums\OrganisationType;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Runs against the dev database inside DatabaseTransactions (tests/Pest.php), so
// every row written here is rolled back. The table may already hold real rows,
// so each test only looks at rows inserted after it started.
//
// Never UPDATE or DELETE audit_logs here: the append-only trigger THROWs, and a
// THROW inside a trigger can leave the wrapping test transaction unusable.

const AUDIT_PASSWORD = 'Audit-Test-Password-42';

beforeEach(function () {
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
});

function newAuditRows(?string $action = null)
{
    return DB::table('audit_logs')
        ->where('id', '>', test()->auditStart)
        ->when($action, fn ($q) => $q->where('action', $action))
        ->orderBy('id')
        ->get();
}

function auditUser(array $attributes = []): User
{
    $moh = Organisation::where('type', OrganisationType::Moh)->firstOrFail();

    // MFA-1: this test exercises the password step; MfaTest covers the two-factor challenge.
    return User::factory()->withoutMfa()->create([
        'email' => 'audit-'.Str::lower(Str::ulid()).'@example.test',
        'password' => AUDIT_PASSWORD,
        'org_id' => $moh->id,
        ...$attributes,
    ]);
}

function auditLogin(string $email, string $password)
{
    return test()->post('/login', ['email' => $email, 'password' => $password]);
}

function metadataOf(object $row): array
{
    return json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
}

it('writes exactly one auth.login.success row with actor and ip', function () {
    $user = auditUser();

    auditLogin($user->email, AUDIT_PASSWORD)->assertRedirect('/dashboard');

    $rows = newAuditRows(AuditAction::LoginSuccess->value);
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect((int) $row->actor_user_id)->toBe($user->id)
        ->and($row->actor_org_id)->toBe($user->org_id)
        ->and($row->actor_type)->toBe('user')
        ->and($row->ip_address)->toBe('127.0.0.1')
        ->and(newAuditRows(AuditAction::LoginFailed->value))->toHaveCount(0);
});

it('records a wrong password as bad_password without storing the password', function () {
    $user = auditUser();

    auditLogin($user->email, 'Wrong-Secret-Pass-77');

    $rows = newAuditRows(AuditAction::LoginFailed->value);
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect(metadataOf($row))->toBe(['reason' => 'bad_password', 'attempted_email' => $user->email])
        ->and((int) $row->actor_user_id)->toBe($user->id)
        ->and(json_encode($row))->not->toContain('Wrong-Secret-Pass-77');
});

it('records an unknown email as unknown_user without storing the password', function () {
    $email = 'nobody-'.Str::lower(Str::ulid()).'@example.test';

    auditLogin($email, 'Wrong-Secret-Pass-77');

    $row = newAuditRows(AuditAction::LoginFailed->value)->sole();
    expect(metadataOf($row))->toBe(['reason' => 'unknown_user', 'attempted_email' => $email])
        ->and($row->actor_type)->toBe('anonymous')
        ->and($row->actor_user_id)->toBeNull()
        ->and(json_encode($row))->not->toContain('Wrong-Secret-Pass-77');
});

it('stores a password typed into the email field as [invalid]', function () {
    auditLogin('My-Real-Password-99', 'whatever');

    $row = newAuditRows(AuditAction::LoginFailed->value)->sole();
    expect(metadataOf($row)['attempted_email'])->toBe('[invalid]')
        ->and(json_encode($row))->not->toContain('My-Real-Password-99')
        ->and(json_encode($row))->not->toContain('my-real-password-99');
});

it('records disabled and locked reasons', function () {
    $disabled = auditUser(['status' => User::STATUS_DISABLED]);
    $locked = auditUser(['locked_until' => now()->addMinutes(10)]);

    auditLogin($disabled->email, AUDIT_PASSWORD);
    auditLogin($locked->email, AUDIT_PASSWORD);

    expect(newAuditRows(AuditAction::LoginFailed->value)->map(fn ($r) => metadataOf($r)['reason'])->all())
        ->toBe(['disabled', 'locked']);
});

it('writes auth.account.locked on the 5th failure', function () {
    $user = auditUser();

    foreach (range(1, AuthenticateUser::MAX_ATTEMPTS) as $ignored) {
        auditLogin($user->email, 'Wrong-Secret-Pass-77');
    }

    expect(newAuditRows(AuditAction::LoginFailed->value))->toHaveCount(AuthenticateUser::MAX_ATTEMPTS);

    $row = newAuditRows(AuditAction::AccountLocked->value)->sole();
    $lockedUntil = metadataOf($row)['locked_until'];

    expect((int) $row->actor_user_id)->toBe($user->id)
        ->and($lockedUntil)->toEndWith('Z')
        // Metadata is whole seconds; users.locked_until is a SQL datetime (rounded fractions).
        ->and(abs(now()->parse($lockedUntil)->diffInSeconds($user->fresh()->locked_until)))->toBeLessThanOrEqual(1);
});

it('writes auth.rate_limited when the login rate limiter trips', function () {
    $user = auditUser();

    foreach (range(1, 6) as $ignored) {
        auditLogin($user->email, 'Wrong-Secret-Pass-77');
    }

    $row = newAuditRows(AuditAction::RateLimited->value)->sole();
    expect(metadataOf($row))->toBe(['attempted_email' => $user->email])
        ->and($row->actor_type)->toBe('anonymous');
});

it('writes auth.logout', function () {
    $user = auditUser();

    $this->actingAs($user)->post('/logout')->assertRedirect();

    $row = newAuditRows(AuditAction::Logout->value)->sole();
    expect((int) $row->actor_user_id)->toBe($user->id);
});

it('strips secret-looking keys from nested metadata', function () {
    Audit::record('test.redaction', metadata: [
        'reason' => 'kept',
        'password' => 'x',
        'nested' => [
            'api_token' => 'x',
            'otp_code' => '123456',
            'keep_me' => 'yes',
            'deeper' => ['client_secret' => 'x', 'Passcode' => 'x', 'note' => 'ok'],
        ],
    ], actor: Audit::ACTOR_SYSTEM);

    $row = newAuditRows('test.redaction')->sole();
    expect(metadataOf($row))->toBe([
        'reason' => 'kept',
        'nested' => ['keep_me' => 'yes', 'deeper' => ['note' => 'ok']],
    ])->and($row->actor_type)->toBe('system');
});

it('stores metadata as valid JSON and occurred_at in UTC', function () {
    $originalTz = date_default_timezone_get();
    date_default_timezone_set('America/Port_of_Spain'); // prove the app clock zone is ignored

    try {
        $before = gmdate('Y-m-d H:i:s');
        Audit::record('test.json', metadata: ['a' => 1, 'b' => ['c' => 'ü']], actor: Audit::ACTOR_SYSTEM);
        $after = gmdate('Y-m-d H:i:s');
    } finally {
        date_default_timezone_set($originalTz);
    }

    $id = newAuditRows('test.json')->sole()->id;
    $row = DB::selectOne(
        'SELECT ISJSON(metadata) AS is_json, CONVERT(varchar(23), occurred_at, 121) AS occurred FROM audit_logs WHERE id = ?',
        [$id],
    );
    $column = DB::selectOne(
        "SELECT t.name AS type, c.scale FROM sys.columns c JOIN sys.types t ON c.user_type_id = t.user_type_id
         WHERE c.object_id = OBJECT_ID('audit_logs') AND c.name = 'occurred_at'",
    );

    expect((int) $row->is_json)->toBe(1)
        ->and(substr($row->occurred, 0, 19))->toBeGreaterThanOrEqual($before)
        ->and(substr($row->occurred, 0, 19))->toBeLessThanOrEqual($after)
        ->and($column->type)->toBe('datetime2')
        ->and((int) $column->scale)->toBe(3);
});

it('rolls the audit row back with the surrounding transaction', function () {
    $marker = 'test.rollback.'.Str::lower(Str::random(8));

    DB::beginTransaction(); // nested inside the test transaction, so a savepoint
    Audit::record($marker, actor: Audit::ACTOR_SYSTEM);
    expect(newAuditRows($marker))->toHaveCount(1);
    DB::rollBack();

    expect(newAuditRows($marker))->toHaveCount(0);
});

it('fails closed in record() but best-effort for auth events', function () {
    $unencodable = ['bad' => INF]; // json_encode throws

    expect(fn () => Audit::record('test.fail', metadata: $unencodable, actor: Audit::ACTOR_SYSTEM))
        ->toThrow(JsonException::class);

    Log::shouldReceive('critical')->once()->with('audit write failed', [
        'action' => AuditAction::LoginFailed->value,
        'exception_class' => JsonException::class,
    ]);

    Audit::recordAuthEvent(AuditAction::LoginFailed, $unencodable, Audit::ACTOR_ANONYMOUS);

    expect(newAuditRows())->toHaveCount(0);
});

it('has an INSTEAD OF UPDATE, DELETE append-only trigger', function () {
    $events = DB::select(
        "SELECT tr.is_instead_of_trigger, te.type_desc
         FROM sys.triggers tr JOIN sys.trigger_events te ON te.object_id = tr.object_id
         WHERE tr.name = 'trg_audit_logs_append_only' AND tr.parent_id = OBJECT_ID('audit_logs')",
    );

    expect(collect($events)->pluck('type_desc')->sort()->values()->all())->toBe(['DELETE', 'UPDATE'])
        ->and(collect($events)->every(fn ($e) => (int) $e->is_instead_of_trigger === 1))->toBeTrue();
});

it('defaults user_type to staff and status to active when not given', function () {
    $user = new User;
    $user->forceFill([
        'name' => 'Default Check',
        'email' => 'defaults-'.Str::lower(Str::ulid()).'@example.test',
        'password' => AUDIT_PASSWORD,
    ])->save();

    $fresh = $user->fresh();
    expect($fresh->user_type)->toBe('staff')
        ->and($fresh->status)->toBe('active');
});
