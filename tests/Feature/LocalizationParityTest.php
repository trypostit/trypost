<?php

declare(strict_types=1);

use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\User\Locale;
use App\Enums\Workspace\ContentLanguage;
use Illuminate\Support\Arr;

test('every UI locale is a supported content language', function () {
    expect(Locale::values())->toEqualCanonicalizing(ContentLanguage::values());
});

test('the default UI locale is a supported content language', function () {
    expect(Locale::DEFAULT->value)->toBeIn(ContentLanguage::values());
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
})->with(Locale::values());

test('every analytics enum value has a display translation', function (string $locale) {
    $analytics = require lang_path("{$locale}/analytics.php");

    foreach (MetricKey::cases() as $metric) {
        expect(Arr::has($analytics, "metrics.{$metric->value}") || Arr::has($analytics, "detail.labels.{$metric->value}"))
            ->toBeTrue("{$locale} is missing a label for metric {$metric->value}");
    }

    foreach (MetricTimeBasis::cases() as $timeBasis) {
        expect(Arr::has($analytics, "detail.time_basis.{$timeBasis->value}"))
            ->toBeTrue("{$locale} is missing a label for time basis {$timeBasis->value}");
    }

    foreach (PublicationContentType::cases() as $contentType) {
        expect(Arr::has($analytics, "detail.content_types.{$contentType->value}"))
            ->toBeTrue("{$locale} is missing a label for content type {$contentType->value}");
    }

    expect(Arr::has($analytics, 'detail.page_title'))->toBeTrue("{$locale} is missing the publication page title");
})->with(Locale::values());

// Key presence alone cannot catch stale wording (same key, incomplete sentence).
// Destructive account/workspace delete copy is additionally asserted in
// tests/Unit/Settings/DeleteAccountCopyTest.php with per-locale content markers.
