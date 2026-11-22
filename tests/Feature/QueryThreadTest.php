<?php

use App\Enums\CaseStatus as S;
use App\Models\ProcurementCase;
use App\Models\QueryRoute;
use App\Models\User;
use App\Queries\QueryAccess;
use App\Queries\QueryView;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

// WORKFLOW-2 (Step 4): the thread stated plainly on the server: From, To, the
// route in words, who holds it now and the next action in one sentence, for
// every state and party. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    $this->seed(QueryRouteSeeder::class);
    Notification::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
});

/** The staff DTO of the one query on $case, as $user sees it. */
function qtView(User $user, ProcurementCase $case): array
{
    test()->actingAs($user);

    return QueryView::forStaff(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $user)['queries'][0];
}

function qtLegalQuery(array $a, string $state, ?string $named = 'tech_named'): ProcurementCase
{
    $case = qvCase($a, S::WITH_LEGAL);
    $route = QueryRoute::query()->where('from_role', 'legal_officer')->where('to_role', 'technocrat')->firstOrFail();
    qvDrive($a, $case, $route, 'legal', 'tech', $named, $state);

    return $case;
}

it('says who it is from and to, the route and the holder', function () {
    $a = qvActors();
    $case = qtLegalQuery($a, 'delivered');
    $thread = qtView($a['legal'], $case)['thread'];

    expect($thread['from'])->toBe($a['legal']->name.' (Legal Unit)')
        ->and($thread['to'])->toBe($a['tech_named']->name.' (Technocrats)')
        ->and($thread['route'])->toBe("Legal Unit to Technocrats via the CMO's Office")
        ->and($thread['via_cmo'])->toBeTrue()
        ->and($thread['holder'])->toBe($a['tech_named']->name);
});

it('gives the next action for every state and party', function (string $state, array $expected) {
    $a = qvActors();
    $case = qtLegalQuery($a, $state);

    foreach ($expected as $party => $sentence) {
        $sentence = str_replace('NAMED', $a['tech_named']->name, $sentence);
        expect(qtView($a[$party], $case)['thread']['next_action'])->toBe($sentence, "{$state} / {$party}");
    }
})->with([
    'held' => ['held', [
        'cmo' => 'Waiting for you to forward this to the Technocrats.',
        'legal' => "Waiting for the CMO's Office to forward this to the Technocrats.",
    ]],
    'delivered' => ['delivered', [
        'cmo' => 'Waiting for a reply from NAMED.',
        'legal' => 'Waiting for a reply from NAMED.',
        'tech' => 'Waiting for your reply.',
        'tech_named' => 'Waiting for your reply.',
    ]],
    'answered' => ['answered', [
        'cmo' => 'Answered. You can close it.',
        'legal' => 'Answered. You can reply or close it.',
        'tech' => 'Answered. Waiting for the Legal Unit to reply or close it.',
    ]],
    'closed' => ['closed', [
        'cmo' => 'Closed. Nobody can add to it.',
        'legal' => 'Closed. Nobody can add to it.',
        'tech' => 'Closed. Nobody can add to it.',
    ]],
]);

it('names the desk when no officer was named', function () {
    $a = qvActors();
    $case = qtLegalQuery($a, 'delivered', null);
    $thread = qtView($a['legal'], $case)['thread'];

    expect($thread['to'])->toBe('Technocrats')
        ->and($thread['holder'])->toBe('Technocrats')
        ->and($thread['next_action'])->toBe('Waiting for a reply from the Technocrats.');
});

it('holds nothing once closed', function () {
    $a = qvActors();
    expect(qtView($a['cmo'], qtLegalQuery($a, 'closed'))['thread']['holder'])->toBeNull();
});

it('marks each message Internal or External, with its author, office and time', function () {
    $a = qvActors();
    $message = qtView($a['cmo'], qtLegalQuery($a, 'delivered'))['messages'][0];

    expect($message['author'])->toBe($a['legal']->name)
        ->and($message['author_office'])->toBe('Legal Unit')
        ->and($message['visibility_label'])->toBe('Internal')
        ->and($message['sent_at'])->toMatch('/^\d{1,2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} (AM|PM)$/');

    // A direct query to the vendor is External; the CMO reads it without replying (R4).
    $case = qvCase($a, S::IN_REVIEW);
    $route = QueryRoute::query()->where('from_role', 'technocrat')->where('to_role', 'vendor_applicant')->firstOrFail();
    qvDrive($a, $case, $route, 'tech', 'vendor', null, 'delivered');

    $cmo = qtView($a['cmo'], $case);
    expect($cmo['messages'][0]['visibility_label'])->toBe('External')
        ->and($cmo['can_reply'])->toBeFalse()
        ->and($cmo['read_only'])->toBeTrue()
        ->and($cmo['thread']['route'])->toBe('Technocrats to '.$a['vendor']->organisation->name.', direct')
        ->and(qtView($a['tech'], $case)['thread']['next_action'])->toBe('Waiting for a reply from '.$a['vendor']->organisation->name.'.');
});

it('gives reply, forward and close only to those allowed', function () {
    $a = qvActors();
    $case = qtLegalQuery($a, 'held');

    $cmo = qtView($a['cmo'], $case);
    expect($cmo['needs_my_forward'])->toBeTrue()
        ->and($cmo['messages'][0]['forward'])->not->toBeNull()
        ->and($cmo['can_close'])->toBeTrue();
    expect(qtView($a['legal'], $case)['messages'][0]['forward'])->toBeNull();

    $case = qtLegalQuery($a, 'delivered');
    expect(qtView($a['tech'], $case)['can_reply'])->toBeTrue()
        ->and(qtView($a['tech_named'], $case)['can_reply'])->toBeTrue()
        // A named officer who is not on the case cannot close it (staff on the case and the CMO's desk only).
        ->and(qtView($a['tech_named'], $case)['can_close'])->toBeFalse();
});

it('keeps the thread wording and internal text out of the vendor DTO', function () {
    $a = qvActors();
    $case = qvCase($a, S::IN_REVIEW);
    $route = QueryRoute::query()->where('from_role', 'technocrat')->where('to_role', 'vendor_applicant')->firstOrFail();
    qvDrive($a, $case, $route, 'tech', 'vendor', null, 'answered');

    test()->actingAs($a['vendor']);
    $vendor = QueryView::forVendor(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $a['vendor']);
    $json = json_encode($vendor);

    expect($vendor[0])->not->toHaveKey('thread')
        ->and($json)->not->toContain($a['tech']->name)
        ->and($json)->not->toContain('Technocrats')
        ->and($json)->not->toContain('visibility_label');
});
