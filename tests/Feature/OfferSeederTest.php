<?php

declare(strict_types=1);

use App\Models\Offer;
use Database\Seeders\OfferSeeder;

test('seeds the local offers on a local install', function () {
    app()->detectEnvironment(fn (): string => 'local');

    (new OfferSeeder)->run();
    (new OfferSeeder)->run();

    expect(Offer::query()->orderBy('slug')->pluck('slug')->all())->toBe(['bf26', 'first-month', 'free-trial-7-days'])
        ->and(Offer::query()->where('slug', 'bf26')->value('show_banner'))->toBeTrue();
});

test('seeds no offer outside a local install', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    (new OfferSeeder)->run();

    expect(Offer::query()->count())->toBe(0);
})->with(['production', 'testing']);
