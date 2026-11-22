<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Models\User;
use App\Support\WorkQueue;
use Database\Seeders\QueryRouteSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// WORKFLOW-2: query fixtures shared by the query visibility, thread and notification tests.

/** @return array<string, User> */
function qvActors(): array
{
    $org = vendorOrg();
    $tag = Str::upper(Str::random(4));

    return [
        'cmo' => caseUser(Role::Cmo, ['name' => "QV CMO {$tag}"]),
        'tech' => caseUser(Role::Technocrat, ['name' => "QV Tech {$tag}"]),
        'tech_unassigned' => caseUser(Role::Technocrat, ['name' => "QV Tech Free {$tag}"]),
        'tech_named' => caseUser(Role::Technocrat, ['name' => "QV Tech Named {$tag}"]),
        'legal' => caseUser(Role::LegalOfficer, ['name' => "QV Legal {$tag}"]),
        'legal_unassigned' => caseUser(Role::LegalOfficer, ['name' => "QV Legal Free {$tag}"]),
        'legal_named' => caseUser(Role::LegalOfficer, ['name' => "QV Legal Named {$tag}"]),
        'ps' => caseUser(Role::PsOffice, ['name' => "QV PS {$tag}"]),
        'cab' => caseUser(Role::CabOfficer, ['name' => "QV CAB {$tag}"]),
        'registry' => caseUser(Role::CmoRegistry, ['name' => "QV Registry {$tag}"]),
        'vendor' => vendorUser($org, ['name' => "QV Vendor {$tag}"]),
        'other_vendor' => vendorUser(vendorOrg(), ['name' => "QV Other Vendor {$tag}"]),
    ];
}

/**
 * A case at $status with the assignments a real case has there: Technocrat
 * and CAB on their tracks (completed once past review), Legal from WITH_LEGAL
 * (completed once past Legal), the registry's intake row completed.
 */
function qvCase(array $a, S $status): ProcurementCase
{
    $case = makeCase([
        'org_id' => $a['vendor']->org_id,
        'created_by' => $a['vendor']->id,
        'status' => $status,
        'reference_no' => 'NR-QV-'.Str::upper(Str::random(8)),
        'submitted_at' => now()->subDays(5),
    ]);

    $pastReview = $status !== S::IN_REVIEW;
    $rows = [
        [Role::CmoRegistry, 'registry', 'intake', true],
        [Role::Technocrat, 'tech', 'technical', $pastReview],
        [Role::CabOfficer, 'cab', 'funding', $pastReview],
    ];
    if ($status !== S::IN_REVIEW) {
        $rows[] = [Role::LegalOfficer, 'legal', 'legal', $status !== S::WITH_LEGAL];
    }
    foreach ($rows as [$role, $key, $purpose, $done]) {
        DB::table('case_assignments')->where('id', assign($case, $role, $a[$key], $done)->id)->update(['purpose' => $purpose]);
    }

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

/**
 * The seven seeded routes: [from, to, case status, raiser key, assigned
 * recipient key, unassigned recipient key|null, named officer key|null].
 *
 * @return array<string, array{0: string, 1: string, 2: S, 3: string, 4: string, 5: string|null, 6: string|null}>
 */
function qvRoutes(): array
{
    return [
        'Technocrat to vendor (direct)' => ['technocrat', 'vendor_applicant', S::IN_REVIEW, 'tech', 'vendor', null, null],
        'Legal to Technocrats via CMO' => ['legal_officer', 'technocrat', S::WITH_LEGAL, 'legal', 'tech', 'tech_unassigned', 'tech_named'],
        'PS to Technocrats via CMO' => ['ps_office', 'technocrat', S::WITH_PS, 'ps', 'tech', 'tech_unassigned', 'tech_named'],
        'PS to Legal via CMO' => ['ps_office', 'legal_officer', S::WITH_PS, 'ps', 'legal', 'legal_unassigned', 'legal_named'],
        'Technocrat to CMO (direct)' => ['technocrat', 'cmo', S::IN_REVIEW, 'tech', 'cmo', null, null],
        'CMO to Legal (direct)' => ['cmo', 'legal_officer', S::RETURNED_TO_CMO, 'cmo', 'legal', 'legal_unassigned', null],
        'CMO to Technocrats (direct)' => ['cmo', 'technocrat', S::RETURNED_TO_CMO, 'cmo', 'tech', 'tech_unassigned', null],
    ];
}

/** Every route x state cell (a direct route has no held state). */
function qvCells(): array
{
    $cells = [];
    foreach (qvRoutes() as $name => $route) {
        $via = QueryRouteSeeder::routes()[array_search($name, array_keys(qvRoutes()), true)]['via_cmo'];
        foreach (['held', 'delivered', 'answered', 'closed'] as $state) {
            if ($state === 'held' && ! $via) {
                continue;
            }
            $cells["{$name} / {$state}"] = [$name, $state];
        }
    }

    return $cells;
}

function qvHeld(CaseQuery $query): ?QueryMessage
{
    return QueryMessage::query()->where('query_id', $query->id)->where('requires_forward', true)->whereNull('forwarded_at')->first();
}

/**
 * Drives one query on $case to $state through the real routes. The CMO names
 * $named (if any) on every forward.
 */
function qvDrive(array $a, ProcurementCase $case, QueryRoute $route, string $raiser, string $recipient, ?string $named, string $state): CaseQuery
{
    test()->actingAs($a[$raiser])->post(route('cases.queries.store', $case), ['route' => $route->id, 'body' => 'Internal words QV-RAISE'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $query = CaseQuery::withoutGlobalScopes()->where('case_id', $case->id)->latest('created_at')->latest('id')->firstOrFail();

    $forward = function (bool $outbound) use ($a, $case, $query, $named) {
        $held = qvHeld($query);
        if ($held !== null) {
            test()->actingAs($a['cmo'])->post(route('cases.queries.forward', [$case, $query->id, $held->id]), $named && $outbound ? ['officer_id' => $a[$named]->id] : [])
                ->assertSessionHasNoErrors()->assertRedirect();
        }
    };

    if ($state === 'held') {
        return $query;
    }
    $forward(true);
    if ($state === 'delivered') {
        return $query;
    }

    $replier = $a[$recipient];
    $replyRoute = $replier->isVendor() ? 'requests.queries.reply' : 'cases.queries.reply';
    test()->actingAs($replier)->post(route($replyRoute, [$case, $query->id]), ['body' => 'Answer words QV-REPLY'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $forward(false);
    if ($state === 'answered') {
        return $query;
    }

    test()->actingAs($a['cmo'])->post(route('cases.queries.close', [$case, $query->id]))->assertSessionHasNoErrors()->assertRedirect();

    return $query;
}

/** Does the party's own page carry the query? [listed, http status of the page]. */
function qvPage(User $user, ProcurementCase $case, CaseQuery $query): array
{
    $response = test()->actingAs($user)->get($user->isVendor() ? route('requests.show', $case->id) : route('cases.show', $case->id));
    if ($response->status() !== 200) {
        return [false, $response->status()];
    }
    $props = pageProps($response);
    $list = $user->isVendor() ? $props['queries'] : $props['queries']['queries'];

    return [in_array($query->id, array_column($list, 'id'), true), 200];
}

/** The query rows of $user's work queue for $case. */
function qvQueueRows(User $user, ProcurementCase $case): array
{
    test()->actingAs($user);
    $found = [];
    $queue = WorkQueue::for($user) ?? [];
    $walk = function ($node) use (&$walk, &$found, $case) {
        if (! is_array($node)) {
            return;
        }
        if (isset($node['step'], $node['case_id']) && array_key_exists($node['step'], WorkQueue::QUERY_STEPS) && $node['case_id'] === $case->id) {
            $found[] = $node['step'];
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($queue);

    return $found;
}
