<?php

declare(strict_types=1);

test('workspace timezone and datetime format keys exist in every locale', function () {
    $locales = collect(glob(lang_path('*')) ?: [])
        ->filter(fn (string $path) => is_dir($path) && is_file("{$path}/settings.php"))
        ->map(fn (string $path) => basename($path))
        ->values()
        ->all();

    expect($locales)->not->toBeEmpty();

    $keys = [
        'workspace.timezone',
        'workspace.timezone_auto',
        'workspace.timezone_description',
        'workspace.datetime_format',
        'workspace.datetime_format_auto',
        'workspace.datetime_format_description',
    ];

    foreach ($locales as $locale) {
        $translations = require lang_path("{$locale}/settings.php");

        foreach ($keys as $key) {
            expect(data_get($translations, $key))
                ->toBeString()
                ->not->toBeEmpty("Missing {$key} in {$locale}");
        }
    }
});
