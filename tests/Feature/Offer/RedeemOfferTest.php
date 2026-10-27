<?php

declare(strict_types=1);

use App\Listeners\StripeEventListener;
use App\Models\Account;
use App\Models\Offer;
use App\Models\User;
use Laravel\Cashier\Events\WebhookHandled;

beforeEach(function () {
    $this->account = Account::factory()->create(['stripe_id' => 'cus_offer123']);
    $this->account->update(['owner_id' => User::factory()->create(['account_id' => $this->account->id])->id]);
    $this->offer = Offer::factory()->limitedTo(1)->create();
});

function subscriptionCreatedWithOffer(?string $offerId, string $status = 'active', string $type = 'customer.subscription.created'): WebhookHandled
{
    return new WebhookHandled([
        'id' => 'evt_'.fake()->uuid(),
        'type' => $type,
        'data' => ['object' => [
            'customer' => 'cus_offer123',
            'id' => 'sub_offer',
            'status' => $status,
            'metadata' => array_filter(['trypost_offer_id' => $offerId]),
        ]],
    ]);
}

test('marks the claimed offer redeemed', function () {
    $this->account->offers()->attach($this->offer, ['claimed_at' => now()]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer($this->offer->id));

    expect($this->account->offers()->first()->pivot->redeemed_at)->not->toBeNull();
});

test('does not overwrite an earlier redemption', function () {
    $redeemedAt = now()->subMonth()->startOfSecond();
    $this->account->offers()->attach($this->offer, ['claimed_at' => $redeemedAt, 'redeemed_at' => $redeemedAt]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer($this->offer->id));

    expect($this->account->offers()->first()->pivot->redeemed_at)->toEqual($redeemedAt->toDateTimeString());
});

test('ignores a subscription without offer metadata', function () {
    $this->account->offers()->attach($this->offer, ['claimed_at' => now()]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer(null));

    expect($this->account->offers()->first()->pivot->redeemed_at)->toBeNull();
});

test('ignores an offer the account never claimed', function () {
    $other = Account::factory()->create();
    $other->offers()->attach($this->offer, ['claimed_at' => now()]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer($this->offer->id));

    expect($this->account->offers()->count())->toBe(0)
        ->and($other->offers()->first()->pivot->redeemed_at)->toBeNull();
});

test('a redeemed offer uses up a place', function () {
    $this->account->offers()->attach($this->offer, ['claimed_at' => now()]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer($this->offer->id));

    expect(Offer::available()->whereKey($this->offer->id)->exists())->toBeFalse();
});

test('an incomplete subscription does not redeem the offer', function () {
    $this->account->offers()->attach($this->offer, ['claimed_at' => now()]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer($this->offer->id, 'incomplete'));

    expect($this->account->offers()->first()->pivot->redeemed_at)->toBeNull();
});

test('the offer is redeemed once an incomplete subscription becomes active', function () {
    $this->account->offers()->attach($this->offer, ['claimed_at' => now()]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer($this->offer->id, 'incomplete'));
    (new StripeEventListener)->handle(subscriptionCreatedWithOffer($this->offer->id, 'active', 'customer.subscription.updated'));

    expect($this->account->offers()->first()->pivot->redeemed_at)->not->toBeNull();
});

test('a trialing subscription redeems the offer', function () {
    $this->account->offers()->attach($this->offer, ['claimed_at' => now()]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer($this->offer->id, 'trialing'));

    expect($this->account->offers()->first()->pivot->redeemed_at)->not->toBeNull();
});

test('ignores offer metadata that is not an offer id', function () {
    $this->account->offers()->attach($this->offer, ['claimed_at' => now()]);

    (new StripeEventListener)->handle(subscriptionCreatedWithOffer('not-a-uuid'));

    expect($this->account->offers()->first()->pivot->redeemed_at)->toBeNull();
});
