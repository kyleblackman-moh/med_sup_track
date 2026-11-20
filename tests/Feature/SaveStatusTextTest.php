<?php

// FORM-ERR-1 (D): a failed autosave says why. A validation refusal asks the
// user to fix the fields marked in red; only a network or server failure says
// "try again". Static checks on the source (there is no JavaScript test runner).

it('words the two failed-save states exactly', function () {
    $chip = file_get_contents(resource_path('js/components/requests/ad-hoc/save-chip.tsx'));

    expect($chip)->toContain("'Not saved. Fix the fields marked in red, then save again.'")
        ->and($chip)->toContain("'Not saved - try again'")
        ->and($chip)->toContain("status === 'invalid' ? SAVE_FAILED_INVALID : status === 'error' ? SAVE_FAILED_ERROR");
});

it('reports a validation refusal as invalid and anything else as error', function () {
    $autosave = file_get_contents(resource_path('js/components/requests/ad-hoc/use-autosave.ts'));

    // Inertia calls onError only when the server answered with validation errors.
    expect($autosave)->toMatch('/onError: \(\) => \{\s*invalid = true;\s*\}/')
        ->and($autosave)->toContain("setStatus(invalid ? 'invalid' : 'error')");
});

it('shows the server\'s own line for a partial save and keeps its fields inline', function () {
    $chip = file_get_contents(resource_path('js/components/requests/ad-hoc/save-chip.tsx'));
    $autosave = file_get_contents(resource_path('js/components/requests/ad-hoc/use-autosave.ts'));
    $page = file_get_contents(resource_path('js/pages/requests/ad-hoc/edit.tsx'));

    // FORM-SAVE-1 (A2): the status text comes from the server (saved.status); the browser only shows it.
    expect($chip)->toContain("status === 'partial' && partialText")
        ->and($autosave)->toContain('saved?.not_saved')
        ->and($autosave)->toContain("setStatus(partialRef.current ? 'partial' : 'saved')")
        ->and($page)->toContain('partialText={autosave.partial?.status}')
        ->and($page)->toContain('...notSavedErrors')
        // Review stays strict while fields are left out.
        ->and($page)->toContain('fieldsLeftOut ||');
});
