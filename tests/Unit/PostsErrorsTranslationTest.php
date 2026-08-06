<?php

declare(strict_types=1);

test('platform unavailable and publish timeout error keys exist in every locale', function () {
    $locales = collect(glob(lang_path('*')) ?: [])
        ->filter(fn (string $path) => is_dir($path) && is_file("{$path}/posts.php"))
        ->map(fn (string $path) => basename($path))
        ->values()
        ->all();

    expect($locales)->not->toBeEmpty();

    foreach ($locales as $locale) {
        $translations = require lang_path("{$locale}/posts.php");

        expect(data_get($translations, 'errors.platform_unavailable'))
            ->toBeString()
            ->not->toBeEmpty("Missing errors.platform_unavailable in {$locale}")
            ->and(data_get($translations, 'errors.platform_unavailable_exhausted'))
            ->toBeString()
            ->not->toBeEmpty("Missing errors.platform_unavailable_exhausted in {$locale}")
            ->and(data_get($translations, 'errors.publishing_timed_out'))
            ->toBeString()
            ->not->toBeEmpty("Missing errors.publishing_timed_out in {$locale}");
    }
});
