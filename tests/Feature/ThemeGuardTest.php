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

// V2-4 (design.md 0.7.2 section 2): the navy shell tokens exist in app.css and
// are exposed to Tailwind, and the shadcn sidebar variables map onto them.
it('defines the navy shell tokens and exposes them in the theme', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    $tokens = [
        'navy', 'navy-deep', 'navy-foreground', 'navy-muted', 'navy-border', 'navy-hover',
        'nav-active', 'nav-active-foreground', 'header', 'header-foreground',
        'sidebar-from', 'sidebar-to', 'primary-deep', 'tile-from', 'tile-to', 'input-bg',
    ];

    foreach ($tokens as $token) {
        expect($css)->toMatch('/^\s*--'.preg_quote($token, '/').':\s*[^;]+;/m')
            ->and($css)->toContain("--color-{$token}: var(--{$token});");
    }

    expect($css)->toContain('--sidebar-background: var(--navy);')
        ->and($css)->toContain('--sidebar-accent: var(--navy-hover);')
        ->and($css)->toContain('--sidebar-primary: var(--nav-active);')
        ->and($css)->toContain('--sidebar-border: var(--navy-border);')
        ->and($css)->toMatch('/--sidebar-ring:\s*hsl\(0, 0%, 100%\);/')
        // MOH blue stays the primary colour.
        ->and($css)->toMatch('/--primary:\s*hsl\(204\.2, 100%, 36\.5%\);/');
});

// V2-4 shell guards (static) -------------------------------------------------

/** The navy sidebar and white header components. */
function shellFiles(): array
{
    return array_map(fn (string $path) => resource_path("js/{$path}"), [
        'components/app-sidebar.tsx', 'components/app-sidebar-header.tsx', 'components/nav-main.tsx',
        'components/nav-user.tsx', 'components/sidebar-watermark.tsx', 'components/app-logo.tsx',
        'components/breadcrumbs.tsx', 'components/app-content.tsx', 'components/ui/sidebar.tsx',
        'layouts/app/app-sidebar-layout.tsx',
    ]);
}

it('has no hex colour and no inline colour style in the sidebar and header components', function () {
    $hex = '/(?<![\w&\/-])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b/';

    foreach (shellFiles() as $file) {
        expect(file_exists($file))->toBeTrue($file);
        expect(themeGuardHits($file, $hex))->toBe([])
            ->and(file_get_contents($file))->not->toMatch('/style=\{\{[^}]*(?:color|background)/i', basename($file).' sets a colour inline');
    }
});

it('marks the active nav link with aria-current and the active style together', function () {
    $nav = file_get_contents(resource_path('js/components/nav-main.tsx'));

    expect($nav)->toContain('isActive={item.url === activeUrl}')
        ->and($nav)->toContain("aria-current={item.url === activeUrl ? 'page' : undefined}");

    // The active style carries the white indicator bar, not colour alone (design.md section 2).
    expect(file_get_contents(resource_path('js/components/ui/sidebar.tsx')))
        ->toContain('data-[active=true]:before:w-[3px]')
        ->toContain('data-[active=true]:font-semibold');
});

it('takes the header title and the tab title from the app name config, never "Laravel"', function () {
    $header = file_get_contents(resource_path('js/components/app-sidebar-header.tsx'));

    expect($header)->toContain('const { name, auth } = usePage<SharedData>().props;')
        ->and($header)->toContain('{name}');

    foreach ([...shellFiles(), resource_path('js/app.tsx'), resource_path('views/app.blade.php')] as $file) {
        expect(file_get_contents($file))->not->toContain('Laravel', basename($file));
    }
});

it('never names NOT_SELECTED in frontend code', function () {
    $hits = collect(themeGuardFiles())->flatMap(fn ($f) => themeGuardHits($f, '/NOT_SELECTED/'))->all();

    expect($hits)->toBe([]);
});
