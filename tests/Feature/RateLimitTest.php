<?php

use App\Enums\Role;
use App\Support\RateLimits;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// SEC-1 (B1, B6): each named limiter answers 429 with Retry-After and a plain
// message with the wait, at its threshold. Priming a limiter (instead of
// sending dozens of real requests) uses the throttle middleware's own key.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Notification::fake();
    Storage::fake('documents');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Fill a named limiter's counter for $key to $hits (the middleware hashes limiter name + key). */
function primeLimiter(string $limiter, string $key, int $hits, int $decaySeconds = 900): void
{
    RateLimiter::increment(md5($limiter.$key), $decaySeconds, $hits);
}

function expectThrottled(TestResponse $response, string $wait = '15 minutes'): void
{
    $response->assertStatus(429)->assertHeader('Retry-After');
    expect($response->getContent())->toBe("Too many attempts. Try again in {$wait}.")
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
}

it('words the wait from Retry-After', function () {
    expect(RateLimits::message(900))->toBe('Too many attempts. Try again in 15 minutes.')
        ->and(RateLimits::message(30))->toBe('Too many attempts. Try again in 1 minute.')
        ->and(RateLimits::message(61))->toBe('Too many attempts. Try again in 2 minutes.');
});

it('limits sign-in to 5 attempts per email and IP in 15 minutes', function () {
    $email = 'nobody-'.Str::lower(Str::random(8)).'@example.test';

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $email, 'password' => 'Wrong-Password-1'])->assertStatus(302);
    }

    expectThrottled($this->post('/login', ['email' => $email, 'password' => 'Wrong-Password-1']));
});

it('limits sign-in per IP across many emails', function () {
    primeLimiter('login', 'login-ip|127.0.0.1', 30);

    expectThrottled($this->post('/login', ['email' => 'fresh@example.test', 'password' => 'x']));
});

it('gives the same answer whether or not the email exists', function () {
    $known = caseUser(Role::Cmo);
    $unknown = 'ghost-'.Str::lower(Str::random(8)).'@example.test';
    primeLimiter('login', "login|{$known->email}|127.0.0.1", 5);
    primeLimiter('login', "login|{$unknown}|127.0.0.1", 5);

    $a = $this->post('/login', ['email' => $known->email, 'password' => 'x']);
    $b = $this->post('/login', ['email' => $unknown, 'password' => 'x']);

    expect([$a->status(), $a->getContent()])->toBe([$b->status(), $b->getContent()]);
});

it('shows the wait on the sign-in page for an Inertia request', function () {
    $email = 'inertia-'.Str::lower(Str::random(8)).'@example.test';
    primeLimiter('login', "login|{$email}|127.0.0.1", 5);

    $this->from('/login')->post('/login', ['email' => $email, 'password' => 'x'], ['X-Inertia' => 'true'])
        ->assertRedirect('/login')
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors(['email' => 'Too many attempts. Try again in 15 minutes.']);
});

it('limits asking for a reset link to 3 per email and IP in 15 minutes', function () {
    $email = 'reset-'.Str::lower(Str::random(8)).'@example.test';

    foreach (range(1, 3) as $i) {
        $this->post('/forgot-password', ['email' => $email])->assertStatus(302);
    }

    expectThrottled($this->post('/forgot-password', ['email' => $email]));
});

it('limits using a reset or activation link', function () {
    $email = 'use-'.Str::lower(Str::random(8)).'@example.test';
    primeLimiter('password-reset', "reset|password.store|{$email}|127.0.0.1", 3);

    expectThrottled($this->post('/reset-password', ['email' => $email, 'token' => 'x', 'password' => 'a', 'password_confirmation' => 'a']));
});

it('limits resending a sign-in link to 3 per account in 15 minutes', function () {
    $admin = caseUser(Role::SystemAdmin);
    $user = caseUser(Role::Cmo);

    foreach (range(1, 3) as $i) {
        $this->actingAs($admin)->post(route('admin.users.activation', $user))->assertStatus(302);
    }

    expectThrottled($this->actingAs($admin)->post(route('admin.users.activation', $user)));
});

it('limits New Request per user', function () {
    $vendor = vendorUser(vendorOrg());
    primeLimiter('create-request', "create-request|{$vendor->id}|127.0.0.1", 20, 600);

    expectThrottled($this->actingAs($vendor)->post(route('requests.store')), '10 minutes');
});

it('limits document uploads per user', function () {
    [$case, $vendor] = newDraft();
    primeLimiter('uploads', "uploads|{$vendor->id}|127.0.0.1", 60, 60);

    expectThrottled($this->actingAs($vendor)->post(route('requests.documents.store', $case), []), '1 minute');
});
