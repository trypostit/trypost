<?php

declare(strict_types=1);

use App\Models\Offer;
use Database\Seeders\OfferSeeder;

test('seeds the local offers and can run again', function () {
    (new OfferSeeder)->run();
    (new OfferSeeder)->run();

    expect(Offer::query()->orderBy('slug')->pluck('slug')->all())->toBe(['bf26', 'first-month', 'free-trial-7-days', 'free-trial-7-days-no-card'])
        ->and(Offer::query()->where('slug', 'bf26')->value('show_banner'))->toBeTrue()
        ->and(Offer::query()->where('slug', 'free-trial-7-days-no-card')->value('requires_card'))->toBeFalse();
});
