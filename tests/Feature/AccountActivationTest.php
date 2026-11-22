<?php

use App\Accounts\AccountActivations;
use App\Enums\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\AccountActivation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// IT-ADMIN (spec 8.2): the emailed activation link. Single-use, signed,
// expires after admin.activation.expire_hours (48); a resend revokes the old
// link; the password follows the app policy plus the breached-password check
// (behind a config flag); MFA enrolment is forced at the first sign-in.

const ACTIVATION_PASSWORD = 'Activation-Password-2026';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    // No call ever leaves the test. The breached-password range API answers
    // with $this->pwnedBody (a test sets it to list a password), else "not found".
    $this->pwnedBody = "0000000000000000000000000000000000A:1\r\n";
    $this->pwnedCalls = 0;
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => function () {
        $this->pwnedCalls++;

        return Http::response($this->pwnedBody);
    }]);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A pending account created through the admin UI, and the activation URL it was emailed. */
function invitedAccount(Role $role = Role::Technocrat): array
{
    confirmAdminPassword();
    $email = 'act-'.Str::lower(Str::ulid()).'@example.test';
    test()->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.store'), ['name' => 'Act Person', 'email' => $email, 'role' => $role->value]);
    auth()->logout();
    test()->flushSession();

    $user = User::where('email', $email)->sole();
    $url = null;
    Notification::assertSentTo($user, AccountActivation::class, function (AccountActivation $n) use (&$url) {
        $url = $n->url;

        return true;
    });

    return [$user, $url];
}

/** [invitation id, token] from an activation URL. */
function activationParts(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return [basename((string) parse_url($url, PHP_URL_PATH)), $query['token']];
}

function activate(string $url, string $password = ACTIVATION_PASSWORD)
{
    [$id, $token] = activationParts($url);

    return test()->post(route('activation.store', $id), ['token' => $token, 'password' => $password, 'password_confirmation' => $password]);
}

it('opens the activation page from the emailed link', function () {
    [$user, $url] = invitedAccount();

    $props = pageProps($this->get($url)->assertOk());

    expect($props['valid'])->toBeTrue()
        ->and($props['email'])->toBe($user->email)
        ->and($props['message'])->toBeNull();
});

it('refuses a tampered or unsigned link (403)', function () {
    [, $url] = invitedAccount();
    [$id, $token] = activationParts($url);

    $this->get(route('activation.show', ['invitation' => $id, 'token' => $token]))->assertForbidden();
    $this->get(str_replace('token=', 'token=x', $url))->assertForbidden();
});

it('activates the account once: status active, the chosen password, the link used up', function () {
    [$user, $url] = invitedAccount();

    activate($url)->assertRedirect(route('login'))->assertSessionHas('status');

    $user->refresh();
    $invitation = UserInvitation::where('user_id', $user->id)->sole();
    expect($user->status)->toBe(User::STATUS_ACTIVE)
        ->and(password_verify(ACTIVATION_PASSWORD, $user->password))->toBeTrue()
        ->and($invitation->accepted_at)->not->toBeNull()
        ->and((int) $invitation->accepted_user_id)->toBe($user->id);

    // Second use: refused, the password stays the one chosen first.
    activate($url, 'Another-Password-2026')->assertSessionHasErrors('token');
    expect(pageProps($this->get($url)->assertOk())['valid'])->toBeFalse()
        ->and(password_verify(ACTIVATION_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('expires the link after 48 hours', function () {
    [$user, $url] = invitedAccount();

    $this->travel(48)->hours();
    $this->travel(1)->minutes();

    // The signature carries the same expiry: the page itself is refused.
    $this->get($url)->assertForbidden();
    activate($url)->assertSessionHasErrors('token');
    expect($user->fresh()->status)->toBe(User::STATUS_INVITED);
});

it('takes its lifetime from config', function () {
    config(['admin.activation.expire_hours' => 2]);
    [$user] = invitedAccount();

    $invitation = UserInvitation::where('user_id', $user->id)->sole();
    expect(abs($invitation->expires_at->diffInMinutes(now()->addHours(2))))->toBeLessThan(2);
});

it('stops the old link working when a new one is sent', function () {
    [$user, $oldUrl] = invitedAccount();
    $newUrl = app(AccountActivations::class)->issue($user, caseUser(Role::SystemAdmin));

    activate($oldUrl)->assertSessionHasErrors('token');
    activate($newUrl)->assertRedirect(route('login'));
    expect($user->fresh()->status)->toBe(User::STATUS_ACTIVE);
});

it('does not activate a disabled account', function () {
    [$user, $url] = invitedAccount();
    confirmAdminPassword();
    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.disable', $user), ['reason' => 'Mistake.']);
    auth()->logout();

    activate($url)->assertSessionHasErrors('token');
    expect($user->fresh()->status)->toBe(User::STATUS_DISABLED);
});

it('requires a password of at least 12 characters with mixed case and a number', function (string $password) {
    [$user, $url] = invitedAccount();

    activate($url, $password)->assertSessionHasErrors('password');
    expect($user->fresh()->status)->toBe(User::STATUS_INVITED);
})->with(['Short-1a', 'alllowercase-123', 'NoNumbersHere-abc']);

it('refuses a breached password when the check is on', function () {
    $password = 'Breached-Password-2026';
    $sha = strtoupper(sha1($password));
    $this->pwnedBody = substr($sha, 5).":42\r\n";
    [$user, $url] = invitedAccount();

    activate($url, $password)->assertSessionHasErrors('password');
    expect($user->fresh()->status)->toBe(User::STATUS_INVITED)
        ->and($this->pwnedCalls)->toBe(1);
});

it('skips the breached-password check when the flag is off', function () {
    config(['admin.passwords.check_breached' => false]);
    [$user, $url] = invitedAccount();

    activate($url)->assertRedirect(route('login'));
    expect($user->fresh()->status)->toBe(User::STATUS_ACTIVE)
        ->and($this->pwnedCalls)->toBe(0);
});

it('refuses sign-in before activation', function () {
    [$user] = invitedAccount();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});

it('forces MFA enrolment at the first sign-in after activation', function () {
    [$user, $url] = invitedAccount();
    activate($url);
    expect($user->fresh()->hasMfa())->toBeFalse();

    $this->post('/login', ['email' => $user->email, 'password' => ACTIVATION_PASSWORD]);
    $this->assertAuthenticatedAs($user->fresh());

    $this->get(route('dashboard'))->assertRedirect(route('security.mfa.setup'));
});

it('stores only a hash of the token', function () {
    [$user, $url] = invitedAccount();
    [, $token] = activationParts($url);

    $invitation = UserInvitation::where('user_id', $user->id)->sole();
    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token);
});
