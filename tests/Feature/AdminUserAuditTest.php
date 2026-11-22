<?php

use App\Accounts\AccountActivations;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 5, IT-ADMIN: audit rows for Admin > Users. Ids and fixed
// codes only, plus actor, target, IP and request id; a typed reason is stored
// in admin_action_reasons and the row carries reason_id and reason_length.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    confirmAdminPassword();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** The one audit row of $action about $user. */
function userAuditRow(string $action, User $user): object
{
    $rows = DB::table('audit_logs')->where('action', $action)->where('subject_type', 'user')->where('subject_id', (string) $user->id)->get();
    expect($rows)->toHaveCount(1);

    return $rows->first();
}

/** No name, email or reason text anywhere in the row's metadata. */
function expectNoTypedText(object $row, ?string $reason, User ...$users): void
{
    foreach ($users as $user) {
        expect($row->metadata)->not->toContain($user->name)
            ->and($row->metadata)->not->toContain($user->email);
    }

    if ($reason !== null) {
        expect($row->metadata)->not->toContain($reason);
    }
}

/** Actor, IP and request id on an admin action's row. */
function expectActorIpAndRequest(object $row, User $actor): void
{
    expect((int) $row->actor_user_id)->toBe($actor->id)
        ->and($row->ip_address)->toBe('127.0.0.1')
        ->and($row->request_id)->toMatch('/^[0-9a-f-]{36}$/');
}

it('audits user.role_changed with the old and new role ids and the reason id', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Technocrat, ['name' => 'Role Person '.Str::random(6)]);
    $reason = 'Transferred to Cost and Budgeting '.Str::random(6);

    $this->actingAs($admin)->put(route('admin.users.role', $user), ['role' => 'cab_officer', 'reason' => $reason]);

    $row = userAuditRow('user.role_changed', $user);
    $meta = json_decode($row->metadata, true);
    expectActorIpAndRequest($row, $admin);
    expect($meta)->toBe([
        'user_id' => $user->id,
        'old_role_ids' => [(int) SpatieRole::findByName('technocrat', 'web')->id],
        'old_roles' => ['technocrat'],
        'new_role_id' => (int) SpatieRole::findByName('cab_officer', 'web')->id,
        'new_role' => 'cab_officer',
        'sessions_ended' => 0,
        'reason_id' => $meta['reason_id'],
        'reason_length' => mb_strlen($reason),
    ])->and(reasonTextFor($meta))->toBe($reason);
    expectNoTypedText($row, $reason, $admin, $user);
});

it('writes no role_changed row when the role is unchanged or refused', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo);

    $this->actingAs($admin)->put(route('admin.users.role', $user), ['role' => 'cmo', 'reason' => 'Same.']);
    $this->actingAs($admin)->put(route('admin.users.role', $user), ['role' => 'vendor_applicant', 'reason' => 'Wrong kind.']);
    $this->actingAs($admin)->put(route('admin.users.role', $user), ['role' => 'system_admin', 'reason' => 'Not here.']);

    expect(DB::table('audit_logs')->where('action', 'user.role_changed')->where('subject_id', (string) $user->id)->count())->toBe(0);
});

it('audits user.disabled with the sessions ended and the reason id', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo, ['name' => 'Disabled Person '.Str::random(6)]);
    DB::table('sessions')->insert([
        'id' => Str::random(40), 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp(),
    ]);
    $reason = 'Left the Ministry '.Str::random(6);

    $this->actingAs($admin)->post(route('admin.users.disable', $user), ['reason' => $reason]);

    $row = userAuditRow('user.disabled', $user);
    $meta = json_decode($row->metadata, true);
    expectActorIpAndRequest($row, $admin);
    expect($meta)->toMatchArray(['user_id' => $user->id, 'sessions_ended' => 1, 'activation_links_revoked' => 0, 'reason_length' => mb_strlen($reason)])
        ->and(reasonTextFor($meta))->toBe($reason);
    expectNoTypedText($row, $reason, $admin, $user);
});

it('audits user.enabled with the reason id', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]);
    $reason = 'Back from leave '.Str::random(6);

    $this->actingAs($admin)->post(route('admin.users.enable', $user), ['reason' => $reason]);

    $row = userAuditRow('user.enabled', $user);
    $meta = json_decode($row->metadata, true);
    expectActorIpAndRequest($row, $admin);
    expect($meta)->toMatchArray(['user_id' => $user->id, 'status' => User::STATUS_ACTIVE])
        ->and(reasonTextFor($meta))->toBe($reason);
    expectNoTypedText($row, $reason, $admin, $user);
});

it('audits mfa.reset_by_admin with the reason id when one is given', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo);
    $reason = 'Lost phone '.Str::random(6);

    $this->actingAs($admin)->post(route('admin.users.mfa-reset', $user), ['reason' => $reason])->assertSessionHas('success');

    $row = userAuditRow('mfa.reset_by_admin', $user);
    expectActorIpAndRequest($row, $admin);
    expect(reasonTextFor(json_decode($row->metadata, true)))->toBe($reason);
    expectNoTypedText($row, $reason, $admin, $user);
});

it('audits user.activation_resent, sent or not', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo, ['status' => User::STATUS_INVITED]);

    $this->actingAs($admin)->post(route('admin.users.activation', $user));

    $row = userAuditRow('user.activation_resent', $user);
    expectActorIpAndRequest($row, $admin);
    expect(json_decode($row->metadata, true))->toBe(['user_id' => $user->id, 'sent' => true]);
    expectNoTypedText($row, null, $admin, $user);
});

it('records a failed resend as sent false, and the failed email as mail.send_failed', function () {
    $user = caseUser(Role::Cmo, ['status' => User::STATUS_INVITED]);
    Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp down'));

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.activation', $user))->assertSessionHas('error');

    expect(json_decode(userAuditRow('user.activation_resent', $user)->metadata, true)['sent'])->toBeFalse()
        ->and(json_decode(userAuditRow('mail.send_failed', $user)->metadata, true))
        ->toBe(['notification' => 'account_activation', 'exception' => RuntimeException::class]);
});

it('audits user.activated with the user as actor', function () {
    $user = caseUser(Role::Cmo, ['status' => User::STATUS_INVITED]);
    $url = app(AccountActivations::class)->issue($user, null);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $id = basename((string) parse_url($url, PHP_URL_PATH));

    app(AccountActivations::class)->accept($id, $query['token'], 'Activated-Password-123');

    $row = userAuditRow('user.activated', $user);
    expect((int) $row->actor_user_id)->toBe($user->id)
        ->and(json_decode($row->metadata, true))->toBe(['user_id' => $user->id, 'invitation_id' => $id]);
});

it('writes no change row for a refused action', function () {
    $admin = caseUser(Role::SystemAdmin);
    $disabled = caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]);

    $this->actingAs($admin)->post(route('admin.users.disable', $admin), ['reason' => 'Me.'])->assertForbidden();
    $this->actingAs($admin)->post(route('admin.users.activation', $disabled))->assertSessionHasErrors('account');

    expect(DB::table('audit_logs')->whereIn('subject_id', [(string) $admin->id, (string) $disabled->id])
        ->whereIn('action', ['user.disabled', 'user.activation_resent'])->count())->toBe(0);
});
