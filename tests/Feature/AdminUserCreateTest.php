<?php

use App\Enums\Role;
use App\Models\User;
use App\Notifications\AccountActivation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// ADMIN-1 Step 2: create a user. No plaintext password is shown or emailed;
// the person gets a link to the existing Reset Password page.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function newUserEmail(): string
{
    return 'admin1-'.Str::lower(Str::ulid()).'@example.test';
}

it('creates a staff user in the MOH with a link to set their password', function () {
    $admin = caseUser(Role::SystemAdmin);
    $email = newUserEmail();

    $response = $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => '  New Technocrat  ',
        'email' => Str::upper($email),
        'role' => 'technocrat',
        'organisation' => '',
    ]);

    $user = User::where('email', $email)->firstOrFail();
    $response->assertRedirect(route('admin.users.show', $user));

    expect($user->name)->toBe('New Technocrat')
        ->and($user->org_id)->toBe(mohOrg()->id)
        ->and($user->user_type)->toBe('staff')
        ->and($user->status)->toBe(User::STATUS_ACTIVE)
        ->and($user->password_change_required)->toBeFalse()
        ->and((int) $user->invited_by)->toBe($admin->id)
        ->and($user->getRoleNames()->all())->toBe(['technocrat'])
        ->and($user->password)->not->toBeEmpty();

    Notification::assertSentTo($user, AccountActivation::class, function (AccountActivation $n) use ($user) {
        // A real broker token for this user, opening the existing Reset Password page.
        $mail = $n->toMail($user);

        return Password::broker()->tokenExists($user, $n->token)
            && str_contains($mail->actionUrl, '/reset-password/'.$n->token)
            && str_contains($mail->actionUrl, 'email='.urlencode($user->email));
    });
});

it('creates a vendor user in a vendor organisation', function () {
    $org = vendorOrg();
    $email = newUserEmail();

    $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.store'), [
        'name' => 'New Vendor', 'email' => $email, 'role' => 'vendor_applicant', 'organisation' => $org->id,
    ])->assertRedirect();

    $user = User::where('email', $email)->firstOrFail();
    expect($user->org_id)->toBe($org->id)
        ->and($user->user_type)->toBe('vendor')
        ->and($user->isVendor())->toBeTrue()
        ->and($user->getRoleNames()->all())->toBe(['vendor_applicant']);
});

it('never shows or emails a password', function () {
    $email = newUserEmail();

    $response = $this->actingAs(caseUser(Role::SystemAdmin))->post(route('admin.users.store'), [
        'name' => 'No Password', 'email' => $email, 'role' => 'cmo',
    ]);

    $user = User::where('email', $email)->firstOrFail();
    $flash = collect(['success', 'info', 'error'])->map(fn ($k) => session($k))->filter()->implode(' ');
    $page = json_encode(pageProps($this->get($response->headers->get('Location'))->assertOk()));

    expect(Str::lower($flash))->not->toContain('password:')
        ->and($page)->not->toContain('"password"');

    Notification::assertSentTo($user, AccountActivation::class, function (AccountActivation $n) use ($user) {
        $text = implode(' ', [...$n->toMail($user)->introLines, ...$n->toMail($user)->outroLines]);

        return ! preg_match('/password\s*:/i', $text);
    });
});

it('tells the admin when mail goes to the log instead of an inbox', function () {
    config(['mail.default' => 'log']);

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->post(route('admin.users.store'), ['name' => 'Logged Mail', 'email' => newUserEmail(), 'role' => 'cmo'])
        ->assertSessionHas('info', fn ($m) => str_contains($m, 'written to the application log'));
});

it('says the email went out when mail is delivered', function () {
    config(['mail.default' => 'smtp']);

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->post(route('admin.users.store'), ['name' => 'Mailed', 'email' => newUserEmail(), 'role' => 'cmo'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'link to set their password has been sent'));
});

it('keeps the account and reports it when the email cannot be sent', function () {
    Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp down'));
    $email = newUserEmail();

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->post(route('admin.users.store'), ['name' => 'Mail Fails', 'email' => $email, 'role' => 'cmo'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'could not be sent') && ! str_contains($m, 'smtp down'));

    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('validates the role and organisation pairing on the server', function (Closure $payload, string $errorKey) {
    $email = newUserEmail();

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->post(route('admin.users.store'), ['name' => 'Pairing', 'email' => $email, ...$payload()])
        ->assertSessionHasErrors($errorKey);

    expect(User::where('email', $email)->exists())->toBeFalse();
})->with([
    'vendor role without an organisation' => [fn () => ['role' => 'vendor_applicant'], 'organisation'],
    'vendor role with the MOH' => [fn () => ['role' => 'vendor_applicant', 'organisation' => mohOrg()->id], 'organisation'],
    'vendor role with an unknown organisation' => [fn () => ['role' => 'vendor_applicant', 'organisation' => '01ZZZZZZZZZZZZZZZZZZZZZZZZ'], 'organisation'],
    'vendor role with an inactive vendor organisation' => [fn () => ['role' => 'vendor_applicant', 'organisation' => tap(vendorOrg(), fn ($o) => $o->forceFill(['active' => false])->save())->id], 'organisation'],
    'staff role with a vendor organisation' => [fn () => ['role' => 'cmo', 'organisation' => vendorOrg()->id], 'organisation'],
    'staff role with the MOH sent explicitly' => [fn () => ['role' => 'cmo', 'organisation' => mohOrg()->id], 'organisation'],
    'unknown role' => [fn () => ['role' => 'superuser'], 'role'],
    'no role' => [fn () => [], 'role'],
]);

it('never creates a vendor user without a vendor organisation, whatever the payload', function (array $extra) {
    $email = newUserEmail();

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->post(route('admin.users.store'), ['name' => 'Sneaky', 'email' => $email, 'role' => 'vendor_applicant', ...$extra]);

    expect(User::where('email', $email)->exists())->toBeFalse();
})->with([
    'no organisation' => [[]],
    'org_id instead' => [['org_id' => 'ignored']],
    'user_type forced' => [['user_type' => 'vendor']],
]);

it('rejects a duplicate email, whatever its case', function () {
    $existing = caseUser(Role::Cmo);

    $this->actingAs(caseUser(Role::SystemAdmin))
        ->post(route('admin.users.store'), ['name' => 'Dup', 'email' => Str::upper($existing->email), 'role' => 'cmo'])
        ->assertSessionHasErrors(['email' => 'A user with this email already exists.']);
});

it('requires a name and a valid email', function () {
    $this->actingAs(caseUser(Role::SystemAdmin))
        ->post(route('admin.users.store'), ['name' => '   ', 'email' => 'not-an-email', 'role' => 'cmo'])
        ->assertSessionHasErrors(['name', 'email']);
});

it('audits user.created with ids and fixed codes only', function () {
    $admin = caseUser(Role::SystemAdmin);
    $org = vendorOrg();
    $email = newUserEmail();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Audited Person', 'email' => $email, 'role' => 'vendor_applicant', 'organisation' => $org->id,
    ]);
    $user = User::where('email', $email)->firstOrFail();

    $row = DB::table('audit_logs')->where('action', 'user.created')->where('subject_id', (string) $user->id)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->actor_user_id)->toBe($admin->id)
        ->and(json_decode($row->metadata, true))->toBe([
            'user_id' => $user->id,
            'role' => 'vendor_applicant',
            'organisation_id' => $org->id,
            'created_via' => 'admin',
        ])
        ->and($row->metadata)->not->toContain('Audited Person')
        ->and($row->metadata)->not->toContain($email);
});

it('sends the create form the roles with the vendor flag and only active vendor organisations', function () {
    $active = vendorOrg();
    $inactive = tap(vendorOrg(), fn ($o) => $o->forceFill(['active' => false])->save());

    $options = pageProps($this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.users.create'))->assertOk())['options'];
    $orgIds = collect($options['organisations'])->pluck('value');

    expect($orgIds)->toContain($active->id)
        ->and($orgIds)->not->toContain($inactive->id)
        ->and($orgIds)->not->toContain(mohOrg()->id)
        ->and(collect($options['roles'])->where('vendor', true)->pluck('value')->all())->toBe(['vendor_applicant']);
});
