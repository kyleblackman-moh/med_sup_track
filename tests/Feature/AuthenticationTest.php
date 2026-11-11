<?php

use App\Actions\Fortify\AuthenticateUser;
use App\Enums\OrganisationType;
use App\Enums\Role;
use App\Enums\UserType;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// Runs against the dev database inside DatabaseTransactions (tests/Pest.php):
// every test rolls back. Emails are random so they never clash with real rows.

const GOOD_PASSWORD = 'Correct-Horse-Battery-12';

function authUser(array $attributes = []): User
{
    return User::factory()->create([
        'email' => Str::lower(Str::ulid()).'@example.test',
        'password' => GOOD_PASSWORD,
        ...$attributes,
    ]);
}

function vendorOrganisation(bool $active): Organisation
{
    return Organisation::create([
        'name' => 'Test Vendor '.Str::ulid(),
        'type' => OrganisationType::Vendor,
        'active' => $active,
    ]);
}

function attemptLogin(string $email, string $password)
{
    return test()->post('/login', ['email' => $email, 'password' => $password]);
}

it('logs in an active user and redirects to the dashboard', function () {
    $user = authUser();

    attemptLogin($user->email, GOOD_PASSWORD)->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('shows the generic error for a wrong password', function () {
    $user = authUser();

    attemptLogin($user->email, 'Wrong-Password-123')
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('blocks a disabled user even with the correct password', function () {
    $user = authUser(['status' => User::STATUS_DISABLED]);

    attemptLogin($user->email, GOOD_PASSWORD)
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('blocks a locked user even with the correct password', function () {
    $user = authUser(['locked_until' => now()->addMinutes(10)]);

    attemptLogin($user->email, GOOD_PASSWORD)
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('allows login again once the lock has expired', function () {
    $user = authUser(['locked_until' => now()->subMinute()]);

    attemptLogin($user->email, GOOD_PASSWORD)->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->locked_until)->toBeNull();
});

it('locks the account for 15 minutes after 5 wrong passwords', function () {
    $user = authUser();

    foreach (range(1, AuthenticateUser::MAX_ATTEMPTS - 1) as $attempt) {
        attemptLogin($user->email, 'Wrong-Password-123');
        expect($user->fresh()->locked_until)->toBeNull()
            ->and($user->fresh()->failed_login_attempts)->toBe($attempt);
    }

    $this->freezeSecond();
    attemptLogin($user->email, 'Wrong-Password-123');

    $locked = $user->fresh()->locked_until;
    expect($locked)->not->toBeNull()
        ->and($locked->equalTo(now()->addMinutes(AuthenticateUser::LOCK_MINUTES)))->toBeTrue();
});

it('blocks a vendor user whose organisation is inactive', function () {
    $user = authUser([
        'user_type' => UserType::Vendor->value,
        'org_id' => vendorOrganisation(active: false)->id,
    ]);

    attemptLogin($user->email, GOOD_PASSWORD)
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('logs in a vendor user whose organisation is active', function () {
    $user = authUser([
        'user_type' => UserType::Vendor->value,
        'org_id' => vendorOrganisation(active: true)->id,
    ]);

    attemptLogin($user->email, GOOD_PASSWORD)->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('records last_login_at and resets failed attempts on success', function () {
    $user = authUser(['failed_login_attempts' => 3, 'last_login_at' => null]);

    $this->freezeSecond();
    attemptLogin($user->email, GOOD_PASSWORD)->assertRedirect('/dashboard');

    $fresh = $user->fresh();
    expect($fresh->last_login_at->equalTo(now()))->toBeTrue()
        ->and($fresh->failed_login_attempts)->toBe(0);
});

it('has no registration routes', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'X', 'email' => 'x@example.test',
        'password' => GOOD_PASSWORD, 'password_confirmation' => GOOD_PASSWORD,
    ])->assertNotFound();
});

it('stores passwords as argon2id hashes', function () {
    $user = authUser();

    expect($user->getRawOriginal('password'))->toStartWith('$argon2id$');
});

it('rehashes a legacy bcrypt password to argon2id on login', function () {
    $user = authUser();
    DB::table('users')->where('id', $user->id)
        ->update(['password' => password_hash(GOOD_PASSWORD, PASSWORD_BCRYPT)]);

    attemptLogin($user->email, GOOD_PASSWORD)->assertRedirect('/dashboard');

    expect($user->fresh()->getRawOriginal('password'))->toStartWith('$argon2id$');
});

it('shows the identical error for an unknown email and a wrong password', function () {
    $user = authUser();

    attemptLogin('nobody-'.Str::ulid().'@example.test', GOOD_PASSWORD);
    $unknownEmail = session('errors')->first('email');

    attemptLogin($user->email, 'Wrong-Password-123');
    $wrongPassword = session('errors')->first('email');

    expect($unknownEmail)->toBe($wrongPassword)
        ->and($unknownEmail)->toBe(__('auth.failed'));
});

it('fires the Failed event on a bad login and Lockout when the account locks', function () {
    Event::fake([Failed::class, Lockout::class]);
    $user = authUser();

    foreach (range(1, AuthenticateUser::MAX_ATTEMPTS) as $ignored) {
        attemptLogin($user->email, 'Wrong-Password-123');
    }

    Event::assertDispatchedTimes(Failed::class, AuthenticateUser::MAX_ATTEMPTS);
    Event::assertDispatchedTimes(Lockout::class, 1);
});

it('shares only user fields and permission names with React', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = authUser();
    $user->assignRole(Role::Cmo->value);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('auth.user', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => 'staff',
        ])
        ->where('auth.can', collect(Role::Cmo->permissionNames())->sort()->values()->all())
    );
});
