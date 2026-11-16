<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\ProcurementCase;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Slice cleanup-1: no getdate() default on case_assignments.assigned_at or
// case_track_events.created_at (the app writes UTC itself; getdate() is local
// time), and the append-only trigger on case_track_events still holds.
// DatabaseTransactions only. Each failing statement is the LAST statement of its
// test: a SQL Server error can end the test's transaction.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function caseTd(): ProcurementCase
{
    return makeCase([
        'status' => CaseStatus::IN_REVIEW,
        'reference_no' => 'NR-TD-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(3),
    ]);
}

function trackEventTd(): int
{
    return DB::table('case_track_events')->insertGetId([
        'case_id' => caseTd()->id, 'track' => 'technical', 'outcome' => 'favourable',
        'actor_id' => caseUser(Role::Technocrat)->id, 'created_at' => CarbonImmutable::now('UTC'),
    ]);
}

it('has no default on case_assignments.assigned_at or case_track_events.created_at, and both stay NOT NULL', function () {
    $defaults = DB::select("
        SELECT t.name AS tbl, c.name AS col
        FROM sys.default_constraints dc
        JOIN sys.columns c ON c.object_id = dc.parent_object_id AND c.column_id = dc.parent_column_id
        JOIN sys.tables t ON t.object_id = dc.parent_object_id
        WHERE (t.name = 'case_assignments' AND c.name = 'assigned_at')
           OR (t.name = 'case_track_events' AND c.name = 'created_at')
    ");

    $nullable = DB::table('INFORMATION_SCHEMA.COLUMNS')
        ->where(fn ($q) => $q->where('TABLE_NAME', 'case_assignments')->where('COLUMN_NAME', 'assigned_at'))
        ->orWhere(fn ($q) => $q->where('TABLE_NAME', 'case_track_events')->where('COLUMN_NAME', 'created_at'))
        ->pluck('IS_NULLABLE')->all();

    expect($defaults)->toBe([])->and($nullable)->toBe(['NO', 'NO']);
});

it('refuses a case_assignments insert without assigned_at', function () {
    $case = caseTd();
    $officer = caseUser(Role::LegalOfficer);

    expect(fn () => DB::table('case_assignments')->insert([
        'case_id' => $case->id, 'role' => 'legal_officer', 'purpose' => 'legal', 'user_id' => $officer->id,
    ]))->toThrow(QueryException::class, 'assigned_at');
});

it('refuses a case_track_events insert without created_at', function () {
    $case = caseTd();
    $tech = caseUser(Role::Technocrat);

    expect(fn () => DB::table('case_track_events')->insert([
        'case_id' => $case->id, 'track' => 'technical', 'outcome' => 'favourable', 'actor_id' => $tech->id,
    ]))->toThrow(QueryException::class, 'created_at');
});

it('still refuses an UPDATE of case_track_events at the database', function () {
    $id = trackEventTd();

    expect(fn () => DB::table('case_track_events')->where('id', $id)->update(['outcome' => 'not_favourable']))
        ->toThrow(QueryException::class, 'append-only');
});

it('still refuses a DELETE of case_track_events at the database', function () {
    $id = trackEventTd();

    expect(fn () => DB::table('case_track_events')->where('id', $id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});
