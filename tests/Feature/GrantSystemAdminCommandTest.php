<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// IT-ADMIN: the System Admin role is given from the command line only, audited.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('gives a staff account the System Admin role, ends its sessions and audits it', function () {
    $user = caseUser(Role::Technocrat);
    DB::table('sessions')->insert([
        'id' => Str::random(40), 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
        'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp(),
    ]);
    $reason = 'Change ticket CHG-'.Str::random(6);

    $this->artisan('app:user-grant-admin', ['email' => Str::upper($user->email), '--reason' => $reason])->assertSuccessful();

    $row = latestAuditFor('user.admin_granted', $user->id);
    expect($user->fresh()->getRoleNames()->all())->toBe(['system_admin'])
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($row->actor_type)->toBe('system')
        ->and($row->actor_user_id)->toBeNull()
        ->and($row->meta)->toMatchArray(['user_id' => $user->id, 'old_roles' => ['technocrat'], 'new_role' => 'system_admin', 'granted_via' => 'console'])
        ->and(reasonTextFor($row->meta))->toBe($reason)
        ->and($row->metadata)->not->toContain($reason);
});

it('refuses and changes nothing', function (Closure $setup, array $options, string $message) {
    $user = $setup();
    $before = $user->getRoleNames()->all();

    $this->artisan('app:user-grant-admin', ['email' => $user->email, ...$options])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect($user->fresh()->getRoleNames()->all())->toBe($before);
})->with([
    'no reason' => [fn () => caseUser(Role::Technocrat), [], 'Give a --reason'],
    'a vendor account' => [fn () => vendorUser(vendorOrg()), ['--reason' => 'x'], 'vendor account'],
    'a disabled account' => [fn () => caseUser(Role::Technocrat, ['status' => User::STATUS_DISABLED]), ['--reason' => 'x'], 'disabled'],
]);

it('fails for an unknown email', function () {
    $this->artisan('app:user-grant-admin', ['email' => 'nobody-'.Str::random(8).'@example.test', '--reason' => 'x'])->assertFailed();
});
