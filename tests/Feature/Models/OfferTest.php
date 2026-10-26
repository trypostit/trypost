<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Offer;

test('an offer that started and has no end is available', function () {
    $offer = Offer::factory()->create();

    expect(Offer::available()->pluck('id')->all())->toBe([$offer->id]);
});

test('an offer that has not started is not available', function () {
    Offer::factory()->upcoming()->create();

    expect(Offer::available()->count())->toBe(0);
});

test('an offer past its end is not available', function () {
    Offer::factory()->expired()->create();

    expect(Offer::available()->count())->toBe(0);
});

test('an offer with an end in the future is available', function () {
    Offer::factory()->create(['ends_at' => now()->addDay()]);

    expect(Offer::available()->count())->toBe(1);
});

test('a full offer is not available', function () {
    $offer = Offer::factory()->limitedTo(1)->create();
    $offer->accounts()->attach(Account::factory()->create(), ['claimed_at' => now(), 'redeemed_at' => now()]);

    expect(Offer::available()->count())->toBe(0);
});

test('claims do not use up places', function () {
    $offer = Offer::factory()->limitedTo(1)->create();
    $offer->accounts()->attach(Account::factory()->create(), ['claimed_at' => now()]);

    expect(Offer::available()->count())->toBe(1);
});

test('current offer is the latest claimed unredeemed available offer', function () {
    $account = Account::factory()->create();
    [$earlier, $later, $redeemed] = Offer::factory()->count(3)->create();

    $account->offers()->attach($earlier, ['claimed_at' => now()->subDays(2)]);
    $account->offers()->attach($later, ['claimed_at' => now()->subDay()]);
    $account->offers()->attach($redeemed, ['claimed_at' => now(), 'redeemed_at' => now()]);

    expect($account->currentOffer()?->id)->toBe($later->id);
});

test('current offer ignores expired claims', function () {
    $account = Account::factory()->create();
    $account->offers()->attach(Offer::factory()->expired()->create(), ['claimed_at' => now()]);

    expect($account->currentOffer())->toBeNull();
});

test('lang key replaces dashes', function () {
    expect(Offer::factory()->make(['slug' => 'black-friday-2026'])->langKey())->toBe('black_friday_2026');
});

test('deleting an account removes its offer claims', function () {
    $account = Account::factory()->create();
    $offer = Offer::factory()->create();
    $account->offers()->attach($offer, ['claimed_at' => now()]);

    $account->delete();

    $this->assertDatabaseMissing('account_offer', ['offer_id' => $offer->id]);
});
