<?php

use App\Models\User;

// Light only (NEXT part 4): no Appearance setting. The old route redirects to
// the profile page, the settings navigation has no Appearance entry, and
// nothing in the app can put the dark class on the page. DatabaseTransactions only.

it('redirects the old appearance route to the profile settings page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/appearance')
        ->assertRedirect('/settings/profile');
});

it('still sends guests to the login page', function () {
    $this->get('/settings/appearance')->assertRedirect('/login');
});

it('has no Appearance entry in the settings navigation and no appearance page', function () {
    $layout = file_get_contents(resource_path('js/layouts/settings/layout.tsx'));

    expect($layout)->not->toContain('/settings/appearance')
        ->and($layout)->not->toMatch("/title:\\s*'Appearance'/")
        ->and(file_exists(resource_path('js/pages/settings/appearance.tsx')))->toBeFalse();
});

it('never applies the dark class from a stored or system preference', function () {
    $files = [
        ...glob(resource_path('js/*.tsx')),
        ...glob(resource_path('js/hooks/*.ts*')),
        ...glob(resource_path('js/components/*.tsx')),
        ...glob(resource_path('js/layouts/*.tsx')),
        ...glob(resource_path('js/layouts/*/*.tsx')),
        resource_path('views/app.blade.php'),
    ];

    foreach ($files as $file) {
        $source = file_get_contents($file);
        expect($source)->not->toMatch("/classList\\.(add|toggle)\\(\\s*'dark'/", $file)
            ->and($source)->not->toContain('prefers-color-scheme')
            ->and($source)->not->toMatch('/(localStorage|cookie)[^\\n]*appearance/i', $file);
    }
});

it('defines no dark token block in app.css', function () {
    expect(file_get_contents(resource_path('css/app.css')))->not->toMatch('/^\.dark\s*\{/m');
});
