<?php

declare(strict_types=1);

use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\User\Locale;
use Illuminate\Support\Arr;

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

    foreach (PublicationContentType::cases() as $contentType) {
        expect(Arr::has($analytics, "detail.content_types.{$contentType->value}"))
            ->toBeTrue("{$locale} is missing a label for content type {$contentType->value}");
    }

    expect(Arr::get($analytics, 'title'))->toBeString()->not->toBeEmpty();
    foreach (['insights.export.button', 'insights.sync.title', 'insights.sync.every_hours', 'insights.columns', 'insights.channels_shown'] as $key) {
        expect(Arr::get($analytics, $key))->toBeString()->not->toBeEmpty("{$locale} is missing {$key}");
    }
})->with(Locale::values());

test('analytics interface copy does not fall back to English', function (string $locale) {
    $english = require lang_path('en/analytics.php');
    $translated = require lang_path("{$locale}/analytics.php");

    foreach ([
        'detail.labels.watch_time_milliseconds',
        'detail.awaiting_metrics',
        'insights.sync.title',
        'insights.sync.new_posts',
        'insights.channels_shown',
        'insights.about.performance',
        'dashboard.no_follower_data',
        'dashboard.import_in_progress',
        'dashboard.no_data_body',
    ] as $key) {
        expect(Arr::get($translated, $key))
            ->not->toBe(Arr::get($english, $key), "{$locale} still uses English for {$key}");
    }
})->with(array_values(array_diff(Locale::values(), [Locale::English->value])));

// Key presence alone cannot catch stale wording (same key, incomplete sentence).
// Destructive account/workspace delete copy is additionally asserted in
// tests/Unit/Settings/DeleteAccountCopyTest.php with per-locale content markers.
