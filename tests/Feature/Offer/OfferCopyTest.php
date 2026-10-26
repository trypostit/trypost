<?php

declare(strict_types=1);

use App\Models\Offer;
use Illuminate\Support\Facades\Lang;

test('every offer has its copy', function () {
    $missing = Offer::query()->get()->flatMap(fn (Offer $offer): array => collect(['name', 'price', 'period'])
        ->map(fn (string $field): string => "offers.{$offer->langKey()}.{$field}")
        ->reject(fn (string $key): bool => Lang::has($key, 'en', false))
        ->all());

    expect($missing->all())->toBe([]);
});
