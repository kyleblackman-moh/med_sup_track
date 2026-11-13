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

it('imports the self-hosted fonts once in the JS entry', function () {
    $entry = file_get_contents(resource_path('js/app.tsx'));

    expect(substr_count($entry, "import '@fontsource-variable/inter'"))->toBe(1)
        ->and(substr_count($entry, "import '@fontsource-variable/jetbrains-mono'"))->toBe(1);
});
