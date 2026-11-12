<?php

use App\Enums\Role;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Visibility of cases (VisibleToUserScope) and its agreement with CasePolicy::view.
// Runs inside DatabaseTransactions; helpers are in tests/Support/CaseHelpers.php.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    // spatie caches permissions across rolled-back transactions.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Letters of the fixture cases the current user can see through the scope. */
function visibleLetters(array $fixture): array
{
    $letters = collect($fixture['cases'])->mapWithKeys(fn ($c, $l) => [$c->id => $l]);

    return ProcurementCase::whereIn('id', fixtureIds($fixture))->pluck('id')
        ->map(fn ($id) => $letters[$id])->sort()->values()->all();
}

it('shows each actor exactly the expected cases', function (string $actor, array $expected) {
    $fixture = visibilityFixture();
    actAs($fixture['actors'][$actor]);

    expect(visibleLetters($fixture))->toBe($expected);
})->with(collect(EXPECTED_VISIBLE)->map(fn ($e, $a) => [$a, $e])->all());

it('stops a completed pool row from granting role-wide visibility', function () {
    $fixture = visibilityFixture();
    $caseA = $fixture['cases']['A'];

    $poolOnly = caseUser(Role::CmoRegistry);
    $withOwnRow = caseUser(Role::CmoRegistry);
    assign($caseA, Role::CmoRegistry, $withOwnRow, completed: true);

    actAs($poolOnly);
    expect(ProcurementCase::whereKey($caseA->id)->exists())->toBeTrue();

    $caseA->assignments()->whereNull('user_id')->update(['completed_at' => now()]);

    expect(ProcurementCase::whereKey($caseA->id)->exists())->toBeFalse();

    actAs($withOwnRow);
    expect(ProcurementCase::whereKey($caseA->id)->exists())->toBeTrue();
});

it('applies the same visibility on every read path', function (string $actor) {
    $fixture = visibilityFixture();
    actAs($fixture['actors'][$actor]);

    $ids = fixtureIds($fixture);
    $expected = EXPECTED_VISIBLE[$actor];
    $visibleIds = collect($expected)->map(fn ($l) => $fixture['cases'][$l]->id)->sort()->values()->all();

    // get() and count()
    expect(ProcurementCase::whereIn('id', $ids)->get()->pluck('id')->sort()->values()->all())->toBe($visibleIds)
        ->and(ProcurementCase::whereIn('id', $ids)->count())->toBe(count($expected));

    // Single-row paths, per case
    foreach ($fixture['cases'] as $letter => $case) {
        $visible = in_array($letter, $expected, true);

        expect(ProcurementCase::whereKey($case->id)->exists())->toBe($visible, "exists {$letter}")
            ->and(ProcurementCase::find($case->id) !== null)->toBe($visible, "find {$letter}")
            ->and(ProcurementCase::whereKey($case->id)->first() !== null)->toBe($visible, "first {$letter}");

        if ($visible) {
            expect(ProcurementCase::findOrFail($case->id)->id)->toBe($case->id);
        } else {
            expect(fn () => ProcurementCase::findOrFail($case->id))->toThrow(ModelNotFoundException::class);
        }
    }

    // Aggregate with groupBy
    $byStatus = ProcurementCase::whereIn('id', $ids)
        ->select('status', DB::raw('COUNT(*) AS n'))->groupBy('status')
        ->pluck('n', 'status')->map(fn ($n) => (int) $n)->sortKeys()->all();
    $expectedByStatus = collect($expected)->countBy(fn ($l) => $fixture['cases'][$l]->status->value)->sortKeys()->all();
    expect($byStatus)->toBe($expectedByStatus);

    // Relationship loads from Organisation (lazy and eager)
    $orgIds = [$fixture['x']->id, $fixture['y']->id];
    $eager = Organisation::with('cases')->whereIn('id', $orgIds)->get()->flatMap->cases->pluck('id');
    $lazy = $fixture['x']->cases()->pluck('id')->merge($fixture['y']->cases()->pluck('id'));
    expect($eager->sort()->values()->all())->toBe($visibleIds)
        ->and($lazy->sort()->values()->all())->toBe($visibleIds);

    // withoutGlobalScopes() is the only bypass
    expect(ProcurementCase::withoutGlobalScopes()->whereIn('id', $ids)->count())->toBe(6);
})->with(['t1', 'vendor_x', 'registry', 'c1', 'system_admin', 'guest', 'cmo']);

it('returns 404, never 403, for a hidden or missing case', function () {
    $fixture = visibilityFixture();

    // Registered on this test's application only; each test boots a fresh app,
    // so the route cannot leak into other tests.
    Route::middleware('web')->get('/__test/cases/{case}', fn (ProcurementCase $case) => response('visible:'.$case->id));

    $this->actingAs($fixture['actors']['t1']);

    $hidden = $this->get('/__test/cases/'.$fixture['cases']['A']->id);
    $missing = $this->get('/__test/cases/'.Str::lower((string) Str::ulid()));
    $visible = $this->get('/__test/cases/'.$fixture['cases']['B']->id);

    $hidden->assertNotFound();
    $missing->assertNotFound();
    expect($hidden->getContent())->toBe($missing->getContent());
    $visible->assertOk()->assertSeeText('visible:'.$fixture['cases']['B']->id);
});

it('gives CasePolicy::view the same answer as the scope for every actor and case', function () {
    $fixture = visibilityFixture();

    foreach ($fixture['actors'] as $actor => $user) {
        foreach ($fixture['cases'] as $letter => $case) {
            $expected = in_array($letter, EXPECTED_VISIBLE[$actor], true);

            expect(Gate::forUser($user)->allows('view', $case))->toBe($expected, "{$actor} view {$letter}");
        }
    }
});
