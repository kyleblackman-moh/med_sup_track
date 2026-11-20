<?php

use App\Enums\CaseStatus;
use App\Enums\QueryType;
use App\Enums\Role;
use App\Models\CaseQuery;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Queries\QueryRoutes;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

// OVERNIGHT-2 (D1): the query routes are data, seeded idempotently and read by role.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(QueryRouteSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('has the query_type column and every other column the loops use', function () {
    expect(Schema::hasColumn('query_routes', 'query_type'))->toBeTrue();
    foreach (['type', 'raised_by_role', 'raised_by_user_id', 'raised_to_role', 'via_cmo', 'status', 'resolved_at', 'resolved_by'] as $column) {
        expect(Schema::hasColumn('queries', $column))->toBeTrue($column);
    }
    foreach (['author_role', 'recipient_role', 'visibility', 'requires_forward', 'forwarded_by_cmo_id', 'forwarded_at'] as $column) {
        expect(Schema::hasColumn('query_messages', $column))->toBeTrue($column);
    }
});

it('seeds the standing routes, and running it again changes nothing', function () {
    $snapshot = fn () => DB::table('query_routes')->orderBy('id')->get(['from_role', 'to_role', 'via_cmo', 'query_type', 'active'])->map(fn ($r) => (array) $r)->all();
    $before = $snapshot();

    $this->seed(QueryRouteSeeder::class);

    expect($snapshot())->toBe($before)
        ->and(DB::table('query_routes')->count())->toBe(count(QueryRouteSeeder::routes()));
});

it('holds the standing rules', function (string $from, string $to, bool $viaCmo, QueryType $type) {
    $route = QueryRoute::query()->where('from_role', $from)->where('to_role', $to)->firstOrFail();

    expect($route->via_cmo)->toBe($viaCmo)
        ->and($route->query_type)->toBe($type)
        ->and($route->active)->toBeTrue();
})->with([
    'Technocrat to vendor, direct' => ['technocrat', 'vendor_applicant', false, QueryType::TECHNICAL],
    'Legal to Technocrat, via CMO' => ['legal_officer', 'technocrat', true, QueryType::LEGAL],
    'PS to Technocrat, via CMO' => ['ps_office', 'technocrat', true, QueryType::PS_TECHNICAL],
    'PS to Legal, via CMO' => ['ps_office', 'legal_officer', true, QueryType::PS_LEGAL],
    'Technocrat to CMO' => ['technocrat', 'cmo', false, QueryType::TECHNICAL],
    'CMO to Legal' => ['cmo', 'legal_officer', false, QueryType::LEGAL],
    'CMO to Technocrats' => ['cmo', 'technocrat', false, QueryType::TECHNICAL],
]);

it('keeps a route an administrator switched off, off', function () {
    DB::table('query_routes')->where('from_role', 'legal_officer')->update(['active' => false]);

    $this->seed(QueryRouteSeeder::class);

    expect(DB::table('query_routes')->where('from_role', 'legal_officer')->value('active'))->toBe('0');
})->skip(fn () => DB::connection()->getDriverName() !== 'sqlsrv', 'Boolean read-back is driver specific.');

it('looks routes up by the user\'s roles, active rows only', function () {
    $legal = caseUser(Role::LegalOfficer);
    $ps = caseUser(Role::PsOffice);
    $admin = caseUser(Role::SystemAdmin);

    expect(QueryRoutes::forRaiser($legal)->pluck('to_role')->all())->toBe(['technocrat'])
        ->and(QueryRoutes::forRaiser($ps)->pluck('to_role')->sort()->values()->all())->toBe(['legal_officer', 'technocrat'])
        ->and(QueryRoutes::forRaiser($admin))->toBeEmpty();

    DB::table('query_routes')->where('from_role', 'legal_officer')->update(['active' => false]);

    expect(QueryRoutes::forRaiser($legal))->toBeEmpty();
});

it('refuses to edit or delete a message, but allows the one forward', function () {
    $case = makeCase(['status' => CaseStatus::WITH_LEGAL, 'reference_no' => 'NR-QR-1']);
    $author = caseUser(Role::LegalOfficer);
    $query = (new CaseQuery)->forceFill([
        'case_id' => $case->id, 'type' => 'LEGAL', 'subject' => 'Legal Query', 'raised_by_role' => 'legal_officer',
        'raised_by_user_id' => $author->id, 'raised_to_role' => 'technocrat', 'via_cmo' => true, 'status' => 'AWAITING_FORWARD',
    ]);
    $query->save();
    $message = (new QueryMessage)->forceFill([
        'query_id' => $query->id, 'author_id' => $author->id, 'author_role' => 'legal_officer', 'recipient_role' => 'technocrat',
        'body' => 'Original words', 'visibility' => 'internal', 'requires_forward' => true,
    ]);
    $message->save();

    expect(fn () => $message->forceFill(['body' => 'Changed'])->save())->toThrow(LogicException::class)
        ->and(fn () => $message->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $query->delete())->toThrow(LogicException::class);

    $fresh = QueryMessage::findOrFail($message->id);
    $fresh->forceFill(['forwarded_by_cmo_id' => caseUser(Role::Cmo)->id, 'forwarded_at' => now()])->save();

    expect(fn () => $fresh->forceFill(['forwarded_at' => now()->addMinute()])->save())->toThrow(LogicException::class)
        ->and(QueryMessage::findOrFail($message->id)->body)->toBe('Original words');
});
