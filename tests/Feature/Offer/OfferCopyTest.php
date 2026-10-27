<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\Offer;
use Illuminate\Support\Facades\Lang;

test('every offer has its copy in every locale', function () {
    $missing = Offer::query()->get()->flatMap(fn (Offer $offer) => collect(Locale::cases())->flatMap(fn (Locale $locale) => collect(['name', 'price', 'period'])
        ->map(fn (string $field): string => "offers.{$offer->langKey()}.{$field}")
        ->reject(fn (string $key): bool => Lang::has($key, $locale->value, false))
        ->map(fn (string $key): string => "{$locale->value}: {$key}")));

    expect($missing->values()->all())->toBe([]);
});
