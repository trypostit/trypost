<?php

declare(strict_types=1);

use App\Models\Offer;
use Database\Seeders\OfferSeeder;

test('seeds the local offers and can run again', function () {
    (new OfferSeeder)->run();
    (new OfferSeeder)->run();

    expect(Offer::query()->orderBy('slug')->pluck('slug')->all())->toBe(['bf26', 'fm1', 'ft7d', 'ft7dnc'])
        ->and(Offer::query()->where('slug', 'bf26')->value('show_banner'))->toBeTrue()
        ->and(Offer::query()->where('slug', 'ft7dnc')->value('requires_card'))->toBeFalse()
        ->and(Offer::query()->where('slug', 'ft7dnc')->value('first_subscription_only'))->toBeTrue();
});
