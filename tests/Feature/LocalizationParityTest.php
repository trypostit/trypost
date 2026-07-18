<?php

declare(strict_types=1);

use App\Enums\Workspace\ContentLanguage;
use Illuminate\Support\Arr;

test('every UI language in config matches the ContentLanguage enum', function () {
    expect(array_keys(config('languages.available')))
        ->toEqualCanonicalizing(ContentLanguage::values());
});

test('the default UI language is a supported content language', function () {
    expect(config('languages.default'))->toBeIn(ContentLanguage::values());
});

test('locale ships every base translation file with identical keys', function (string $locale) {
    $missingFiles = [];
    $keyDrift = [];

    foreach (glob(lang_path('en/*.php')) as $basePath) {
        $file = basename($basePath, '.php');
        $localePath = lang_path("{$locale}/{$file}.php");

        if (! file_exists($localePath)) {
            $missingFiles[] = "{$file}.php";

            continue;
        }

        $baseKeys = array_keys(Arr::dot(require $basePath));
        $localeKeys = array_keys(Arr::dot(require $localePath));

        $missing = array_diff($baseKeys, $localeKeys);
        $extra = array_diff($localeKeys, $baseKeys);

        if ($missing !== [] || $extra !== []) {
            $keyDrift[$file] = [
                'missing' => array_values($missing),
                'extra' => array_values($extra),
            ];
        }
    }

    expect($missingFiles)->toBe([], "{$locale} is missing translation files: ".implode(', ', $missingFiles));
    expect($keyDrift)->toBe([], "{$locale} has key drift: ".json_encode($keyDrift, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->with(ContentLanguage::values());
