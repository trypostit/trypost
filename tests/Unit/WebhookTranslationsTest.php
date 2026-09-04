<?php

declare(strict_types=1);

use App\Enums\Webhook\EventType;

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

test('every webhook event type has a translated label', function () {
    $originalLocale = app()->getLocale();

    $locales = collect(glob(lang_path('*')) ?: [])
        ->filter(fn (string $path) => is_dir($path) && is_file("{$path}/webhooks.php"))
        ->map(fn (string $path) => basename($path))
        ->values()
        ->all();

    foreach ($locales as $locale) {
        app()->setLocale($locale);

        foreach (EventType::cases() as $event) {
            $key = 'webhooks.events.'.str_replace('.', '_', $event->value);

            expect(__($key))->not->toBe($key, "Missing {$key} in {$locale}");
        }
    }

    app()->setLocale($originalLocale);
});

test('http status reason keys resolve in php', function () {
    expect(__('webhooks.http_reasons.200'))->toBe('OK')
        ->and(__('webhooks.http_reasons.unknown'))->toBe('Unknown')
        ->and(__('webhooks.show.status_code', ['code' => '404', 'reason' => 'Not Found']))->toBe('404 - Not Found')
        ->and(__('webhooks.delete.cancel'))->toBe('Cancel');
});
