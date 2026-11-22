<?php

use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// FIX-1 (A2): no Remember me.

it('ignores a posted remember field: no remember-me cookie', function () {
    // MFA-1: this test exercises the password step; MfaTest covers the two-factor challenge.
    $user = withoutMfa(caseUser(Role::Cmo));

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

    $response->assertStatus(302);
    expect(Auth::id())->toBe($user->id)
        ->and(collect($response->headers->getCookies())->filter(fn ($c) => str_starts_with($c->getName(), 'remember_')))->toBeEmpty()
        ->and(Auth::viaRemember())->toBeFalse();
});

it('has no Remember me on the sign-in page', function () {
    $page = file_get_contents(resource_path('js/pages/auth/login.tsx'));

    expect(Str::lower($page))->not->toContain('remember');
});
