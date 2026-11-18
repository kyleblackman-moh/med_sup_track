<?php

// UI-4: static guard (same style as RowLinkGuardTest). Colours exist only as
// tokens in resources/css/app.css (design.md section 2): no hex colour literal
// and no arbitrary colour value in resources/js, and no external font URL.

/** Every .ts and .tsx file under resources/js (app.css is not here). */
function themeGuardFiles(): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    return collect(iterator_to_array($files, false))
        ->filter(fn (SplFileInfo $f) => in_array($f->getExtension(), ['ts', 'tsx'], true))
        ->map(fn (SplFileInfo $f) => $f->getPathname())
        ->values()->all();
}

/** Line numbers (1-based) of $source where $pattern matches, as "file:line: text". */
function themeGuardHits(string $file, string $pattern): array
{
    $hits = [];
    foreach (preg_split('/\R/', file_get_contents($file)) as $i => $line) {
        if (preg_match($pattern, $line)) {
            $hits[] = basename($file).':'.($i + 1).': '.trim($line);
        }
    }

    return $hits;
}

it('scans the frontend', function () {
    expect(count(themeGuardFiles()))->toBeGreaterThan(50);
});

it('has no hex colour literals in resources/js', function () {
    // #rgb, #rgba, #rrggbb, #rrggbbaa as a whole word, inside quotes or a class.
    $pattern = '/(?<![\w&\/-])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b/';
    $hits = collect(themeGuardFiles())->flatMap(fn ($f) => themeGuardHits($f, $pattern))->all();

    expect($hits)->toBe([]);
});

it('has no arbitrary colour values in resources/js', function () {
    // A colour utility with a bracketed value that holds a colour: bg-[#fff], text-[var(--x)],
    // ring-[rgb(...)], shadow-[0_0_0_1px_hsl(...)]. Bracketed sizes (text-[13px]) are fine.
    $pattern = '/\b(?:bg|text|border(?:-[trblxy])?|ring(?:-offset)?|outline|fill|stroke|from|via|to|decoration|accent|caret|divide|placeholder|shadow)-\[[^\]]*(?:#[0-9a-fA-F]|rgba?\(|hsla?\(|oklch\(|var\(--)/';
    $hits = collect(themeGuardFiles())->flatMap(fn ($f) => themeGuardHits($f, $pattern))->all();

    expect($hits)->toBe([]);
});

it('loads no font from an external URL in resources/js', function () {
    $pattern = '/fonts\.(?:googleapis|gstatic|bunny)\.|typekit|https?:\/\/[^\'"\s]*\.(?:woff2?|ttf|otf)\b/i';
    $hits = collect(themeGuardFiles())->flatMap(fn ($f) => themeGuardHits($f, $pattern))->all();

    expect($hits)->toBe([]);
});
