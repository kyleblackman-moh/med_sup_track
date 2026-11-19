<?php

use App\Enums\Role;
use App\Http\Middleware\SecurityHeaders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

// SEC-1 (B3, B6): security headers on a normal page, a redirect and an error;
// CSP in Report-Only mode; HSTS only over HTTPS.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function expectSecurityHeaders(TestResponse $response): void
{
    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('Content-Security-Policy');
}

it('sends the headers on a normal page', function () {
    expectSecurityHeaders($this->actingAs(caseUser(Role::Cmo))->get(route('dashboard'))->assertOk());
});

it('sends the headers on a redirect', function () {
    expectSecurityHeaders($this->get(route('dashboard'))->assertRedirect(route('login')));
});

it('sends the headers on an error', function () {
    expectSecurityHeaders($this->actingAs(caseUser(Role::Cmo))->get('/cases/01ZZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound());
});

it('sends HSTS over HTTPS only', function () {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('uses this Content-Security-Policy, report-only', function () {
    $policy = $this->get('/login')->headers->get('Content-Security-Policy-Report-Only');

    expect($policy)->toBe(SecurityHeaders::contentSecurityPolicy())
        ->and($policy)->toStartWith("default-src 'self'; script-src 'self' 'unsafe-inline'")
        ->and($policy)->toContain("frame-ancestors 'none'")
        ->and($policy)->toContain("object-src 'none'");
});

it('can enforce the policy from config', function () {
    config(['security.headers.csp_report_only' => false]);

    $this->get('/login')->assertHeader('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
});
