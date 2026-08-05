<?php

declare(strict_types=1);

/**
 * Regression guard: display dates must use localized dayjs formats (LL/LLL/lll/LT)
 * or @/date helpers — not English/Portuguese-locked format strings.
 */
test('frontend display dates do not hardcode english or portuguese format literals', function () {
    $root = resource_path('js');
    $forbidden = [
        "format('D MMM YYYY",
        'format("D MMM YYYY',
        "format('MMM D",
        'format("MMM D',
        "format('D [de]",
        'format("D [de]',
        '[às]',
        '[at]',
        'Today at',
        'Just now',
        '4:21 PM',
        '4:18 PM',
        'Jan 20, 2026',
        'Jan 21, 2026',
        'DD/MM/YYYY HH:mm',
    ];

    $violations = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'ts', 'js'], true)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = str_replace(base_path().'/', '', $file->getPathname()).": {$needle}";
            }
        }
    }

    expect($violations)->toBeEmpty(
        "Hardcoded date display literals found:\n".implode("\n", $violations)
    );
});

test('date helpers use localized dayjs formats', function () {
    $contents = file_get_contents(resource_path('js/date.ts'));

    expect($contents)
        ->toContain("format('LL')")
        ->toContain("format('LLL')")
        ->toContain("format('lll')")
        ->toContain("format('L LT')")
        ->not->toContain('[de]')
        ->not->toContain('[às]');
});
