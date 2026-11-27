<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use Illuminate\Support\Facades\Lang;

test('every offer has its price in every locale, and its banner in all or none', function () {
    $offers = Lang::get('offers', [], Locale::DEFAULT->value);

    $missing = collect(Locale::cases())->flatMap(fn (Locale $locale) => collect($offers)
        ->flatMap(fn (array $copy, string $offer): array => collect(['price', 'period', 'checkout_button'])
            ->when(array_key_exists('name', $copy) || array_key_exists('headline', $copy), fn ($fields) => $fields->push('name', 'headline'))
            ->map(fn (string $field): string => "offers.{$offer}.{$field}")
            ->all())
        ->reject(fn (string $key): bool => Lang::has($key, $locale->value, false))
        ->map(fn (string $key): string => "{$locale->value}: {$key}"));

    expect($offers)->not->toBeEmpty()
        ->and($missing->values()->all())->toBe([]);
});
