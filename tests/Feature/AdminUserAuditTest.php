<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 5: audit rows for Admin > Users, ids and fixed codes only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
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

/** No name or email of either party anywhere in the row's metadata. */
function expectNoTypedText(object $row, User ...$users): void
{
    foreach ($users as $user) {
        expect($row->metadata)->not->toContain($user->name)
            ->and($row->metadata)->not->toContain($user->email);
    }
}

it('audits user.role_changed with the old and new role ids', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Technocrat, ['name' => 'Role Person '.Str::random(6)]);

    $this->actingAs($admin)->put(route('admin.users.role', $user), ['role' => 'cab_officer']);

    $row = userAuditRow('user.role_changed', $user);
    expect((int) $row->actor_user_id)->toBe($admin->id)
        ->and(json_decode($row->metadata, true))->toBe([
            'user_id' => $user->id,
            'old_role_ids' => [(int) SpatieRole::findByName('technocrat', 'web')->id],
            'old_roles' => ['technocrat'],
            'new_role_id' => (int) SpatieRole::findByName('cab_officer', 'web')->id,
            'new_role' => 'cab_officer',
        ]);
    expectNoTypedText($row, $admin, $user);
});

it('writes no role_changed row when the role is unchanged or refused', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo);

    $this->actingAs($admin)->put(route('admin.users.role', $user), ['role' => 'cmo']);
    $this->actingAs($admin)->put(route('admin.users.role', $user), ['role' => 'vendor_applicant']);

    expect(DB::table('audit_logs')->where('action', 'user.role_changed')->where('subject_id', (string) $user->id)->count())->toBe(0);
});

it('audits user.disabled with the number of sessions ended', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo, ['name' => 'Disabled Person '.Str::random(6)]);
    DB::table('sessions')->insert([
        'id' => Str::random(40), 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($admin)->post(route('admin.users.disable', $user));

    $row = userAuditRow('user.disabled', $user);
    expect((int) $row->actor_user_id)->toBe($admin->id)
        ->and(json_decode($row->metadata, true))->toBe(['user_id' => $user->id, 'sessions_ended' => 1]);
    expectNoTypedText($row, $admin, $user);
});

it('audits user.enabled', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]);

    $this->actingAs($admin)->post(route('admin.users.enable', $user));

    $row = userAuditRow('user.enabled', $user);
    expect((int) $row->actor_user_id)->toBe($admin->id)
        ->and(json_decode($row->metadata, true))->toBe(['user_id' => $user->id]);
    expectNoTypedText($row, $admin, $user);
});

it('audits user.activation_resent, sent or not', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo);

    $this->actingAs($admin)->post(route('admin.users.activation', $user));

    $row = userAuditRow('user.activation_resent', $user);
    expect((int) $row->actor_user_id)->toBe($admin->id)
        ->and(json_decode($row->metadata, true))->toBe(['user_id' => $user->id, 'sent' => true]);
    expectNoTypedText($row, $admin, $user);
});

it('records a failed resend as sent false', function () {
    $user = caseUser(Role::Cmo);
    Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp down'));

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.activation', $user))->assertSessionHas('error');

    expect(json_decode(userAuditRow('user.activation_resent', $user)->metadata, true)['sent'])->toBeFalse();
});

it('writes no audit row for a refused action', function () {
    $admin = caseUser(Role::SystemAdmin);
    $disabled = caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]);

    $this->actingAs($admin)->post(route('admin.users.disable', $admin))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.users.activation', $disabled))->assertSessionHasErrors('account');

    expect(DB::table('audit_logs')->whereIn('subject_id', [(string) $admin->id, (string) $disabled->id])
        ->whereIn('action', ['user.disabled', 'user.activation_resent'])->count())->toBe(0);
});
