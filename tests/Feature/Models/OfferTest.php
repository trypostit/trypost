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

test('an offer the account redeemed is no longer available to it, only to others', function () {
    $offer = Offer::factory()->create();
    $account = Account::factory()->create();
    $account->offers()->attach($offer, ['claimed_at' => now(), 'redeemed_at' => now()]);

    expect(Offer::availableTo($account)->whereKey($offer->id)->exists())->toBeFalse()
        ->and(Offer::availableTo(Account::factory()->create())->whereKey($offer->id)->exists())->toBeTrue();
});

test('a first-subscription offer is not available to an account that was billed before', function () {
    $offer = Offer::factory()->firstSubscriptionOnly()->create();
    $account = Account::factory()->create();
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_former',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_monthly_test',
    ]);

    expect(Offer::availableTo($account)->whereKey($offer->id)->exists())->toBeFalse()
        ->and(Offer::availableTo(Account::factory()->create())->whereKey($offer->id)->exists())->toBeTrue();
});

test('current offer is empty without a claim', function () {
    Offer::factory()->create();

    expect(Account::factory()->create()->currentOffer())->toBeNull();
});

test('the factory builds the three real offers with their copy', function (string $state, string $slug) {
    $offer = Offer::factory()->{$state}()->create();

    expect($offer->slug)->toBe($slug)
        ->and(__("offers.{$offer->langKey()}.price"))->not->toBe("offers.{$offer->langKey()}.price");
})->with([
    'black friday' => ['blackFriday', 'bf26'],
    'first month' => ['firstMonth', 'fm1'],
    'free trial' => ['freeTrial', 'ft7d'],
]);

test('a first-subscription offer is not available to an account that had a trial without a card, even once Cashier cleared it', function () {
    $offer = Offer::factory()->firstSubscriptionOnly()->create();
    $account = Account::factory()->create(['trial_ends_at' => null]);
    $account->offers()->attach(Offer::factory()->trial(7)->withoutCard()->create(), ['claimed_at' => now()->subWeek(), 'redeemed_at' => now()->subWeek()]);

    expect(Offer::availableTo($account)->whereKey($offer->id)->exists())->toBeFalse()
        ->and($account->isFirstTimeSubscriber())->toBeFalse();
});

test('a trial needs at least one day', function (?int $days, bool $hasTrial) {
    $offer = Offer::factory()->withoutCard()->make(['trial_days' => $days]);

    expect($offer->hasTrial())->toBe($hasTrial)
        ->and($offer->startsWithoutCard())->toBe($hasTrial);
})->with([
    'no trial' => [null, false],
    'zero days' => [0, false],
    'seven days' => [7, true],
]);
