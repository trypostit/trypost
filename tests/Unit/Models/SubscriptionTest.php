<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->now = CarbonImmutable::now('UTC')->startOfMinute();
    $this->travelTo($this->now);
});

test('billed keeps the account subscriptions that ever billed', function () {
    $account = Account::factory()->create();
    $active = subscribeAccount($account);
    $canceled = subscribeAccount($account, ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);
    subscribeAccount($account, ['stripe_status' => 'incomplete']);
    subscribeAccount($account, ['stripe_status' => 'incomplete_expired']);
    subscribeAccount($account, ['type' => 'legacy']);

    expect(Subscription::query()->billed()->pluck('id')->all())->toEqualCanonicalizing([$active->id, $canceled->id])
        ->and(Subscription::query()->get()->filter(fn (Subscription $subscription): bool => $subscription->type === Account::SUBSCRIPTION_NAME && $subscription->becameBillable())->pluck('id')->all())
        ->toEqualCanonicalizing([$active->id, $canceled->id]);
});

test('live keeps the subscriptions without an end or with one still to come', function () {
    $account = Account::factory()->create();
    $open = subscribeAccount($account);
    $cancelling = subscribeAccount($account, ['ends_at' => $this->now->addDay()]);
    subscribeAccount($account, ['stripe_status' => 'canceled', 'ends_at' => $this->now->subSecond()]);
    subscribeAccount($account, ['stripe_status' => 'canceled', 'ends_at' => $this->now]);

    expect(Subscription::query()->live()->pluck('id')->all())->toEqualCanonicalizing([$open->id, $cancelling->id]);
});

test('paying keeps the live active and past due account subscriptions, as isPaying reads one', function () {
    $account = Account::factory()->create();
    $active = subscribeAccount($account);
    $pastDue = subscribeAccount($account, ['stripe_status' => 'past_due', 'ends_at' => $this->now->addDay()]);
    subscribeAccount($account, ['stripe_status' => 'trialing']);
    subscribeAccount($account, ['ends_at' => $this->now->subDay()]);
    subscribeAccount($account, ['type' => 'legacy']);

    expect(Subscription::query()->paying()->pluck('id')->all())->toEqualCanonicalizing([$active->id, $pastDue->id])
        ->and(Subscription::query()->get()->filter->isPaying()->pluck('id')->all())->toEqualCanonicalizing([$active->id, $pastDue->id]);
});

test('a subscription is alive from its start until its end', function () {
    $subscription = subscribeAccount(Account::factory()->create(), ['ends_at' => $this->now->addDays(10), 'created_at' => $this->now->subDays(10)]);

    expect($subscription->isAliveAt($this->now->subDays(11)))->toBeFalse()
        ->and($subscription->isAliveAt($this->now->subDays(10)))->toBeTrue()
        ->and($subscription->isAliveAt($this->now->addDays(10)->subSecond()))->toBeTrue()
        ->and($subscription->isAliveAt($this->now->addDays(10)))->toBeFalse()
        ->and($subscription->endedBy($this->now->addDays(10)->subSecond()))->toBeFalse()
        ->and($subscription->endedBy($this->now->addDays(10)))->toBeTrue();
});

test('a subscription without an end never ends', function () {
    $subscription = subscribeAccount(Account::factory()->create());

    expect($subscription->endedBy($this->now->addYears(5)))->toBeFalse()
        ->and($subscription->isAliveAt($this->now->addYears(5)))->toBeTrue();
});

test('the paying subscription is the one not cancelling, whatever order they were made in', function (bool $cancellingFirst) {
    $account = Account::factory()->create();
    $make = fn (array $attributes): Subscription => subscribeAccount($account, $attributes);

    if ($cancellingFirst) {
        $cancelling = $make(['ends_at' => $this->now->addDays(5), 'created_at' => $this->now->subMonth()]);
        $renewed = $make([]);
    } else {
        $renewed = $make([]);
        $cancelling = $make(['ends_at' => $this->now->addDays(5), 'created_at' => $this->now->subMonth()]);
    }

    expect($account->fresh()->payingSubscription()->id)->toBe($renewed->id);
})->with(['cancelling made first' => true, 'renewed made first' => false]);

test('an account without a live paid subscription pays through none', function () {
    $account = Account::factory()->create();
    subscribeAccount($account, ['stripe_status' => 'trialing']);
    subscribeAccount($account, ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);

    expect($account->fresh()->payingSubscription())->toBeNull();
});
