<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use Illuminate\Support\Facades\Lang;

test('every offer has its whole copy in every locale', function () {
    $offers = array_keys(Lang::get('offers', [], Locale::DEFAULT->value));

    $missing = collect(Locale::cases())->flatMap(fn (Locale $locale) => collect($offers)
        ->crossJoin(['name', 'headline', 'price', 'period'])
        ->map(fn (array $pair): string => "offers.{$pair[0]}.{$pair[1]}")
        ->reject(fn (string $key): bool => Lang::has($key, $locale->value, false))
        ->map(fn (string $key): string => "{$locale->value}: {$key}"));

    expect($offers)->not->toBeEmpty()
        ->and($missing->values()->all())->toBe([]);
});
