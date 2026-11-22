<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Notifications\Cases\CaseSubmitted;
use App\Notifications\Cases\HardcopyReceived;
use App\Notifications\Queries\QueryRaised;
use App\Notifications\Vendor\VendorQueryReceived;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// NOTIF-2 (Step 6): the in-app bell. Real rows in the notifications table (no
// Notification::fake), the user's own rows only, and opening a case clears
// its notifications from the count. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Mail::fake();

    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org);
    $this->tech = caseUser(Role::Technocrat);
    $this->other = caseUser(Role::Technocrat);
    $this->caseA = bellCase($this, 'A');
    $this->caseB = bellCase($this, 'B');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function bellCase($t, string $tag): ProcurementCase
{
    $case = makeCase([
        'org_id' => $t->org->id,
        'created_by' => $t->vendor->id,
        'status' => S::IN_REVIEW,
        'reference_no' => "NR-BELL-{$tag}-".Str::upper(Str::random(6)),
        'submitted_at' => now()->subDay(),
    ]);
    assign($case, Role::Technocrat, $t->tech);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

it('stores an action notification as a pointer row with case_id, and an info one in the bell only', function () {
    $this->tech->notifyNow(CaseSubmitted::for($this->caseA));
    $this->tech->notifyNow(HardcopyReceived::for($this->caseA));

    $rows = DB::table('notifications')->where('notifiable_id', $this->tech->id)->where('notifiable_type', User::class)->orderBy('created_at')->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        $data = json_decode($row->data, true);
        expect($row->case_id)->toBe($this->caseA->id)
            ->and(array_keys($data))->toBe(['case_id', 'reference', 'event', 'kind', 'title', 'url'])
            ->and($data['case_id'])->toBe($this->caseA->id)
            ->and($data['url'])->toBe(route('cases.show', $this->caseA->id));
    }
    expect(collect($rows)->map(fn ($r) => json_decode($r->data, true)['kind'])->sort()->values()->all())->toBe(['action', 'info'])
        ->and(CaseSubmitted::for($this->caseA)->via($this->tech))->toContain('mail')
        ->and(HardcopyReceived::for($this->caseA)->via($this->tech))->not->toContain('mail');
});

it('counts, lists, and marks the signed-in user\'s own notifications read', function () {
    $this->tech->notifyNow(CaseSubmitted::for($this->caseA));
    $this->tech->notifyNow(new QueryRaised($this->caseB->id, $this->caseB->reference_no));
    $this->other->notifyNow(CaseSubmitted::for($this->caseA));

    $this->actingAs($this->tech)->getJson(route('notifications.unread-count'))->assertOk()->assertExactJson(['count' => 2]);

    $list = $this->actingAs($this->tech)->getJson(route('notifications.index'))->assertOk()->json();
    expect($list['total'])->toBe(2)
        ->and($list['unread'])->toBe(2)
        ->and(array_keys($list['data'][0]))->toBe(['id', 'kind', 'event', 'title', 'url', 'read', 'created_at'])
        ->and(json_encode($list))->not->toContain('App\\\\Notifications');

    $mine = $list['data'][0]['id'];
    $theirs = DB::table('notifications')->where('notifiable_id', $this->other->id)->value('id');

    $this->actingAs($this->tech)->postJson(route('notifications.read', $theirs))->assertNotFound();
    $this->actingAs($this->tech)->postJson(route('notifications.read', $mine))->assertOk()->assertExactJson(['count' => 1]);
    $this->actingAs($this->tech)->postJson(route('notifications.read-all'))->assertOk()->assertExactJson(['count' => 0]);

    expect(DB::table('notifications')->where('notifiable_id', $this->other->id)->whereNull('read_at')->count())->toBe(1);
});

it('needs a signed-in user', function () {
    $this->getJson(route('notifications.unread-count'))->assertUnauthorized();
    $this->postJson(route('notifications.read-all'))->assertUnauthorized();
});

it('shares unreadNotificationsCount on every page', function () {
    $this->tech->notifyNow(CaseSubmitted::for($this->caseA));

    $props = pageProps($this->actingAs($this->tech)->get(route('dashboard')));
    expect($props['unreadNotificationsCount'])->toBe(1)
        ->and($props['can'])->not->toHaveKey('unreadNotificationsCount');
});

it('clears a case\'s notifications from the count when the staff user opens the case, and only that case\'s', function () {
    $this->tech->notifyNow(CaseSubmitted::for($this->caseA));
    $this->tech->notifyNow(new QueryRaised($this->caseA->id, $this->caseA->reference_no));
    $this->tech->notifyNow(CaseSubmitted::for($this->caseB));

    $props = pageProps($this->actingAs($this->tech)->get(route('cases.show', $this->caseA->id))->assertOk());

    expect($props['unreadNotificationsCount'])->toBe(1)
        ->and($this->tech->unreadNotifications()->pluck('case_id')->all())->toBe([$this->caseB->id]);
});

it('clears a request\'s notifications when the vendor opens it', function () {
    $this->vendor->notifyNow(VendorQueryReceived::for($this->caseA));
    $this->vendor->notifyNow(VendorQueryReceived::for($this->caseB));

    $props = pageProps($this->actingAs($this->vendor)->get(route('requests.show', $this->caseA->id))->assertOk());

    expect($props['unreadNotificationsCount'])->toBe(1);
});

it('does not mark anything read when the case cannot be opened', function () {
    $this->other->notifyNow(CaseSubmitted::for($this->caseA));

    $this->actingAs($this->other)->get(route('cases.show', $this->caseA->id))->assertNotFound();

    expect($this->other->unreadNotifications()->count())->toBe(1);
});
