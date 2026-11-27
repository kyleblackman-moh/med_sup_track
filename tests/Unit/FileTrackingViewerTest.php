<?php

use App\Workflow\FileTracking;

// Kyle, 2026-11-26: "Where Is The File" shows a Technocrat, CAB or Legal
// officer the times of their own part only (FileTracking::forViewer). Plain
// arrays: no DB.

function viewerStage(string $status, ?string $unit, int $visit, bool $open = false): array
{
    return [
        'status' => $status, 'unit' => $unit, 'unit_label' => $unit, 'label' => $status, 'visit' => $visit,
        'holders' => [['role' => 'x', 'label' => 'X', 'name' => 'Someone']],
        'entered_at' => '1 Nov 2026, 9:00 am', 'entered_at_iso' => '2026-11-01T09:00:00-04:00',
        'left_at' => $open ? null : '2 Nov 2026, 9:00 am', 'left_at_iso' => $open ? null : '2026-11-02T09:00:00-04:00',
        'open' => $open, 'duration_seconds' => 86400, 'duration' => '1 day',
    ];
}

function viewerTracking(): array
{
    $stages = [
        viewerStage('SUBMITTED', 'cmo', 1),
        viewerStage('IN_REVIEW', 'technocrats', 1),
        viewerStage('WITH_LEGAL', 'legal', 1, open: true),
    ];
    $step = fn (string $lane, string $kind, int $stage) => [
        'lane' => $lane, 'kind' => $kind, 'label' => 'Step', 'stage' => $stage,
        'entered_at' => '1 Nov 2026', 'left_at' => '2 Nov 2026', 'duration' => '1 day', 'open' => false, 'column' => $stage, 'number' => 1,
    ];

    return [
        'current' => ['key' => 'legal', 'label' => 'Legal Unit', 'received_at' => '1 Nov 2026', 'received_at_iso' => '2026-11-01', 'seconds' => 86400, 'days' => '1 day'],
        'units' => [
            ['key' => 'cmo', 'label' => "CMO's Office", 'seconds' => 86400, 'days' => '1 day', 'visited' => true, 'current' => false],
            ['key' => 'legal', 'label' => 'Legal Unit', 'seconds' => 86400, 'days' => '1 day', 'visited' => true, 'current' => true],
        ],
        'stages' => $stages,
        'path' => ['lanes' => [], 'columns' => 3, 'steps' => [
            $step('cmo', 'stage', 0), $step('technocrats', 'stage', 1), $step('cab', 'beside', 1), $step('legal', 'stage', 2),
        ]],
    ];
}

it('leaves everything for the CMO and PS Offices', function () {
    $out = FileTracking::forViewer(viewerTracking(), null);

    expect($out['restricted'])->toBeFalse()
        ->and(array_column($out['stages'], 'mine'))->toBe([true, true, true])
        ->and(array_column($out['stages'], 'duration'))->toBe(['1 day', '1 day', '1 day'])
        ->and($out['current']['days'])->toBe('1 day');
});

it('shows CAB only its own part: the first review and the funding check', function () {
    $out = FileTracking::forViewer(viewerTracking(), ['cab']);
    $steps = collect($out['path']['steps']);

    expect($out['restricted'])->toBeTrue()
        ->and(array_column($out['stages'], 'mine'))->toBe([false, true, false])
        ->and($out['stages'][0]['duration'])->toBeNull()
        ->and($out['stages'][0]['entered_at'])->toBeNull()
        ->and($out['stages'][0]['holders'])->toBe([])
        ->and($out['stages'][1]['duration'])->toBe('1 day')
        ->and($steps->firstWhere('lane', 'cab')['duration'])->toBe('1 day')
        ->and($steps->firstWhere('lane', 'legal')['duration'])->toBeNull()
        ->and($steps->firstWhere('lane', 'cmo')['entered_at'])->toBeNull()
        // The file is at Legal: CAB sees where, not since when.
        ->and($out['current']['label'])->toBe('Legal Unit')
        ->and($out['current']['days'])->toBeNull()
        ->and(collect($out['units'])->firstWhere('key', 'legal')['seconds'])->toBe(0);
});

it('shows a Legal officer the Legal visit and how long the file has been with them', function () {
    $out = FileTracking::forViewer(viewerTracking(), ['legal']);

    expect(array_column($out['stages'], 'mine'))->toBe([false, false, true])
        ->and($out['current']['days'])->toBe('1 day')
        ->and(collect($out['path']['steps'])->firstWhere('lane', 'technocrats')['duration'])->toBeNull();
});
