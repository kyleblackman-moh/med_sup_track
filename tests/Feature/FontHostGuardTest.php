<?php

// UI-1: fonts are self-hosted. No third-party font host may appear in the page
// shell or the stylesheets (design.md section 1).

const FONT_HOSTS = ['fonts.bunny.net', 'fonts.googleapis.com', 'fonts.gstatic.com'];

function fontGuardFiles(): array
{
    $css = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('css'), FilesystemIterator::SKIP_DOTS));

    return [
        resource_path('views/app.blade.php'),
        ...array_map(fn (SplFileInfo $f) => $f->getPathname(), iterator_to_array($css, false)),
    ];
}

it('loads no fonts from a third-party host', function () {
    $files = fontGuardFiles();

    expect($files)->toContain(resource_path('views/app.blade.php'))
        ->and(count($files))->toBeGreaterThan(1);

    foreach ($files as $file) {
        $source = file_get_contents($file);

        foreach (FONT_HOSTS as $host) {
            expect(str_contains($source, $host))->toBeFalse(basename($file)." references {$host}");
        }
    }
});

// UI-4: JetBrains Mono moved from a whole-package JS import (every subset) to a
// latin-only @font-face in app.css, like Roboto. Changed on purpose.
it('imports no font package in the JS entry, and no Inter', function () {
    $entry = file_get_contents(resource_path('js/app.tsx'));

    expect($entry)->not->toContain('@fontsource')
        ->and($entry)->not->toContain('@fontsource-variable/inter');
});

it('declares Roboto and JetBrains Mono latin-only from the installed packages and uses them for the font tokens', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($css, '@font-face'))->toBe(2)
        ->and($css)->toContain("url('../../node_modules/@fontsource-variable/roboto/files/roboto-latin-wght-normal.woff2')")
        ->and($css)->toContain("url('../../node_modules/@fontsource-variable/jetbrains-mono/files/jetbrains-mono-latin-wght-normal.woff2')")
        ->and($css)->toMatch("/--font-sans:\\s*'Roboto Variable',/")
        ->and($css)->toMatch("/--font-mono:\\s*'JetBrains Mono Variable',/")
        ->and($css)->not->toContain('Inter Variable');
});
