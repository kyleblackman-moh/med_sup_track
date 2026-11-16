<?php

use App\Enums\OrganisationType;
use App\Models\Organisation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// app:organisation-create: active VENDOR organisations. DatabaseTransactions only.

function coName(): string
{
    return 'Vendor Ltd '.Str::ulid();
}

function coAudits()
{
    return DB::table('audit_logs')->where('action', 'organisation_created');
}

it('creates an active vendor organisation', function () {
    $name = coName();

    $code = Artisan::call('app:organisation-create', ['name' => $name, '--short-name' => 'VL']);
    $org = Organisation::where('name', $name)->sole();

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain($org->id)
        ->and($org->type)->toBe(OrganisationType::Vendor)
        ->and($org->active)->toBeTrue()
        ->and($org->short_name)->toBe('VL');
});

it('refuses a duplicate name and changes nothing', function () {
    $existing = Organisation::create(['name' => coName(), 'type' => OrganisationType::Vendor, 'active' => false]);
    $orgs = Organisation::count();
    $audits = coAudits()->count();

    $code = Artisan::call('app:organisation-create', ['name' => $existing->name, '--short-name' => 'NEW']);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('already exists')
        ->and(Organisation::count())->toBe($orgs)
        ->and($existing->fresh()->short_name)->toBeNull()
        ->and($existing->fresh()->active)->toBeFalse()
        ->and(coAudits()->count())->toBe($audits);
});

it('audits organisation_created with only the id and type', function () {
    $name = 'Distinctive Supplies '.Str::ulid();

    Artisan::call('app:organisation-create', ['name' => $name, '--short-name' => 'DSX']);
    $org = Organisation::where('name', $name)->sole();

    $row = coAudits()->where('subject_id', $org->id)->sole();
    $raw = json_encode($row);

    expect(json_decode($row->metadata, true))->toBe(['organisation_id' => $org->id, 'type' => 'VENDOR'])
        ->and($row->actor_type)->toBe('system')
        ->and($raw)->not->toContain('Distinctive Supplies')
        ->and($raw)->not->toContain('DSX');
});
