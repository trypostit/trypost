<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function webhookTranslationKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $keys = [...$keys, ...webhookTranslationKeys($value, $path)];

            continue;
        }

        $keys[] = $path;
    }

    return $keys;
}

test('every locale has the same webhook translation keys as english', function () {
    $english = webhookTranslationKeys(require lang_path('en/webhooks.php'));

    $locales = collect(glob(lang_path('*')) ?: [])
        ->filter(fn (string $path) => is_dir($path) && is_file("{$path}/webhooks.php"))
        ->map(fn (string $path) => basename($path))
        ->reject(fn (string $locale) => $locale === 'en')
        ->values()
        ->all();

    expect($locales)->not->toBeEmpty();

    foreach ($locales as $locale) {
        expect(webhookTranslationKeys(require lang_path("{$locale}/webhooks.php")))
            ->toEqual($english, "Missing or extra webhook keys in {$locale}");
    }
});

test('http status reason keys resolve in php', function () {
    expect(__('webhooks.http_reasons.200'))->toBe('OK')
        ->and(__('webhooks.http_reasons.unknown'))->toBe('Unknown')
        ->and(__('webhooks.show.status_code', ['code' => '404', 'reason' => 'Not Found']))->toBe('404 - Not Found')
        ->and(__('webhooks.delete.cancel'))->toBe('Cancel');
});
