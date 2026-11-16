<?php

use App\Enums\Role;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

// password_change_required (set by app:user-create): a flagged user can only
// change their password or sign out; a successful change clears the flag.

const PCR_CURRENT = 'Current-Passw0rd-2026';
const PCR_NEW = 'Brand-New-Passw0rd-2026';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function pcrUser(bool $flagged): User
{
    $user = caseUser(Role::Cmo, ['password' => Hash::make(PCR_CURRENT)]);
    $user->forceFill(['password_change_required' => $flagged])->save();

    return $user;
}

/** Every signed-in GET route without parameters, except the allowed ones. @return list<string> */
function pcrGuardedUris(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => in_array('GET', $r->methods(), true)
            && in_array('auth', $r->gatherMiddleware(), true)
            && $r->parameterNames() === []
            && ! in_array($r->getName(), EnsurePasswordChanged::ALLOWED, true))
        ->map(fn (RoutingRoute $r) => '/'.ltrim($r->uri(), '/'))
        ->values()->all();
}

it('redirects a flagged user from every other signed-in route to the password page', function () {
    $user = pcrUser(true);
    $uris = pcrGuardedUris();

    expect(count($uris))->toBeGreaterThan(5)->and($uris)->toContain('/dashboard', '/cases', '/settings/profile');

    foreach ($uris as $uri) {
        $this->actingAs($user)->get($uri)->assertRedirect(route('password.edit'));
    }

    // Writes are blocked too, not just pages.
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'X', 'email' => $user->email])
        ->assertRedirect(route('password.edit'));
    expect($user->fresh()->name)->not->toBe('X');
});

it('still lets a flagged user open the password page and sign out', function () {
    $user = pcrUser(true);

    $this->actingAs($user)->get(route('password.edit'))->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('leaves an unflagged user alone', function () {
    $user = pcrUser(false);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('cases.index'))->assertOk();
});

it('keeps the flag when the update fails and clears it only on success', function () {
    $user = pcrUser(true);

    $this->actingAs($user)->from(route('password.edit'))->put(route('password.update'), [
        'current_password' => 'wrong-password',
        'password' => PCR_NEW,
        'password_confirmation' => PCR_NEW,
    ])->assertSessionHasErrors('current_password');
    expect($user->fresh()->password_change_required)->toBeTrue();

    $this->actingAs($user)->from(route('password.edit'))->put(route('password.update'), [
        'current_password' => PCR_CURRENT,
        'password' => PCR_NEW,
        'password_confirmation' => 'does-not-match-1A',
    ])->assertSessionHasErrors('password');
    expect($user->fresh()->password_change_required)->toBeTrue();

    $this->actingAs($user)->from(route('password.edit'))->put(route('password.update'), [
        'current_password' => PCR_CURRENT,
        'password' => PCR_NEW,
        'password_confirmation' => PCR_NEW,
    ])->assertSessionHasNoErrors();

    $fresh = $user->fresh();
    expect($fresh->password_change_required)->toBeFalse()
        ->and(Hash::check(PCR_NEW, $fresh->password))->toBeTrue();

    $this->actingAs($fresh)->get(route('dashboard'))->assertOk();
});

it('rejects keeping the same password on a forced change', function () {
    $user = pcrUser(true);

    $this->actingAs($user)->from(route('password.edit'))->put(route('password.update'), [
        'current_password' => PCR_CURRENT,
        'password' => PCR_CURRENT,
        'password_confirmation' => PCR_CURRENT,
    ])->assertSessionHasErrors('password');

    $fresh = $user->fresh();
    expect($fresh->password_change_required)->toBeTrue()
        ->and(Hash::check(PCR_CURRENT, $fresh->password))->toBeTrue();
});
