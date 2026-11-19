<?php

use App\Http\Resources\Requests\AdHocFormConfig;
use App\Submissions\FormWording;

// FORM-SAVE-1 (A3, A4): choosing a type never waits on other edits being
// valid, and in-app navigation asks before leaving unsaved edits. Static
// checks on the page source plus the server's wording.

function editPageSource(): string
{
    return file_get_contents(resource_path('js/pages/requests/ad-hoc/edit.tsx'));
}

it('words the form\'s notices on the server', function () {
    expect(AdHocFormConfig::for()['messages'])->toBe([
        'leave_unsaved' => 'You have changes that are not saved yet. Leave anyway?',
        'type_saved_others_pending' => FormWording::TYPE_SAVED_OTHERS_PENDING,
    ]);
});

it('sends the type change even when the pending save fails, and says why other edits are pending', function () {
    preg_match('/const changeType = async \(code: string\) => \{(.*?)\n    \};/s', editPageSource(), $m);
    $body = $m[1];

    expect($body)->toContain('const othersSaved = await autosave.flush();')
        ->and($body)->toContain("router.put(\n            route('requests.type'")
        ->and($body)->toContain('setTypeNotice(config.messages.type_saved_others_pending)')
        // The old early return and its misleading review message are gone from this path.
        ->and($body)->not->toContain('return;')
        ->and($body)->not->toContain('setReviewFailed');
});

it('asks before in-app navigation leaves unsaved edits, and keeps the unload warning', function () {
    $page = editPageSource();
    $autosave = file_get_contents(resource_path('js/components/requests/ad-hoc/use-autosave.ts'));

    expect($page)->toContain("router.on('before'")
        // Only real navigation away: not prefetches, partial reloads, saves, or this page.
        ->and($page)->toContain("visit.method !== 'get' || visit.prefetch || visit.only.length > 0 || visit.url.pathname === window.location.pathname")
        ->and($page)->toContain('unsavedRef.current() && !window.confirm(config.messages.leave_unsaved)')
        ->and($page)->toContain('event.preventDefault()')
        // Unsaved = edits not sent yet, a save in flight, or fields the last save left out.
        ->and($autosave)->toContain('return !e.stopped && (e.version > e.savedVersion || e.inFlight || partialRef.current);')
        ->and($autosave)->toContain("window.addEventListener('beforeunload', warn)")
        // No browser storage.
        ->and($page.$autosave)->not->toMatch('/localStorage|sessionStorage|indexedDB/');
});
