<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Notifications\Cases\CaseSubmitted;
use App\Notifications\Cases\HardcopyReceived;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// NOTIF-3: the "View all" page (Inertia, paginated, All / Action / Unread) and
// the dropdown's JSON (notifications.latest). The signed-in user's own rows
// only. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Mail::fake();

    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org);
    $this->tech = caseUser(Role::Technocrat);
    $this->other = caseUser(Role::Technocrat);
    $this->case = notificationsPageCase($this);
});

afterEach(function () {
    $this->travelBack();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function notificationsPageCase($t): ProcurementCase
{
    $case = makeCase([
        'org_id' => $t->org->id,
        'created_by' => $t->vendor->id,
        'status' => S::IN_REVIEW,
        'reference_no' => 'NR-NOTIF-'.Str::upper(Str::random(6)),
        'submitted_at' => now()->subDay(),
    ]);
    assign($case, Role::Technocrat, $t->tech);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

/** $count rows for $user, one minute apart (oldest first), alternating action and info. */
function notificationsPageSeed($t, $user, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $t->travel(1)->minutes();
        $user->notifyNow($i % 2 === 0 ? CaseSubmitted::for($t->case) : HardcopyReceived::for($t->case));
    }
}

it('needs a signed-in user for the page and the JSON', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
    $this->getJson(route('notifications.latest'))->assertUnauthorized();
});

it('renders the page with the user\'s own notifications, newest first, with the case reference', function () {
    notificationsPageSeed($this, $this->tech, 2);
    notificationsPageSeed($this, $this->other, 3);

    $this->actingAs($this->tech)->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/index')
            ->where('filter', 'all')
            ->where('notifications.total', 2)
            ->has('notifications.data', 2)
            ->where('notifications.data.0.kind', 'info')
            ->where('notifications.data.1.kind', 'action')
            ->where('notifications.data.0.reference', $this->case->reference_no)
            ->where('notifications.data.0.read', false)
            ->where('unreadNotificationsCount', 2));
});

it('pages the list 20 at a time and keeps the filter in the page links', function () {
    notificationsPageSeed($this, $this->tech, 25);

    $first = pageProps($this->actingAs($this->tech)->get(route('notifications.index'))->assertOk())['notifications'];
    expect($first['data'])->toHaveCount(20)
        ->and($first['total'])->toBe(25)
        ->and($first['last_page'])->toBe(2)
        ->and($first['from'])->toBe(1)
        ->and($first['to'])->toBe(20)
        ->and($first['prev_page_url'])->toBeNull()
        ->and($first['next_page_url'])->not->toBeNull();

    $second = pageProps($this->actingAs($this->tech)->get(route('notifications.index', ['page' => 2]))->assertOk())['notifications'];
    expect($second['data'])->toHaveCount(5)
        ->and($second['current_page'])->toBe(2)
        ->and($second['next_page_url'])->toBeNull()
        ->and(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')))->toBe([]);

    // 13 action rows fit on one page; all 25 are unread, so Unread has a second page whose link keeps the filter.
    $action = pageProps($this->actingAs($this->tech)->get(route('notifications.index', ['filter' => 'action']))->assertOk())['notifications'];
    expect($action['total'])->toBe(13)
        ->and($action['last_page'])->toBe(1);

    $unread = pageProps($this->actingAs($this->tech)->get(route('notifications.index', ['filter' => 'unread']))->assertOk())['notifications'];
    expect($unread['total'])->toBe(25)
        ->and($unread['next_page_url'])->toContain('filter=unread');
});

it('filters to action rows and to unread rows', function () {
    notificationsPageSeed($this, $this->tech, 4); // action, info, action, info
    $oldestAction = $this->tech->notifications()->reorder()->orderBy('created_at')->firstOrFail();
    $oldestAction->markAsRead();

    $action = pageProps($this->actingAs($this->tech)->get(route('notifications.index', ['filter' => 'action']))->assertOk());
    expect($action['filter'])->toBe('action')
        ->and(array_unique(array_column($action['notifications']['data'], 'kind')))->toBe(['action'])
        ->and($action['notifications']['total'])->toBe(2);

    $unread = pageProps($this->actingAs($this->tech)->get(route('notifications.index', ['filter' => 'unread']))->assertOk());
    expect($unread['filter'])->toBe('unread')
        ->and($unread['notifications']['total'])->toBe(3)
        ->and(array_unique(array_column($unread['notifications']['data'], 'read')))->toBe([false])
        ->and(array_column($unread['notifications']['data'], 'id'))->not->toContain($oldestAction->id);
});

it('reports a bad filter instead of ignoring it', function () {
    $this->actingAs($this->tech)->get(route('notifications.index', ['filter' => 'everything']))
        ->assertRedirect(route('notifications.index'))
        ->assertSessionHasErrors('filter');
});

it('never lists another user\'s notifications on any filter', function () {
    notificationsPageSeed($this, $this->other, 3);

    foreach (['all', 'action', 'unread'] as $filter) {
        $props = pageProps($this->actingAs($this->tech)->get(route('notifications.index', ['filter' => $filter]))->assertOk());
        expect($props['notifications']['total'])->toBe(0);
    }
    expect($this->actingAs($this->tech)->getJson(route('notifications.latest'))->assertOk()->json('data'))->toBe([]);
});

it('gives the dropdown the latest 10 of the user\'s own rows and the unread count', function () {
    notificationsPageSeed($this, $this->tech, 12);
    notificationsPageSeed($this, $this->other, 2);
    $newest = $this->tech->notifications()->reorder()->orderByDesc('created_at')->firstOrFail();

    $json = $this->actingAs($this->tech)->getJson(route('notifications.latest'))->assertOk()->json();
    expect($json['data'])->toHaveCount(10)
        ->and($json['data'][0]['id'])->toBe($newest->id)
        ->and($json['unread'])->toBe(12)
        ->and(array_keys($json))->toBe(['data', 'unread']);
});

it('marks all read from the page with a normal form post and redirects back', function () {
    notificationsPageSeed($this, $this->tech, 3);

    $this->actingAs($this->tech)->from(route('notifications.index'))
        ->post(route('notifications.read-all'))
        ->assertRedirect(route('notifications.index'));

    expect($this->tech->unreadNotifications()->count())->toBe(0);
});
