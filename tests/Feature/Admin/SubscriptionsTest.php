<?php

declare(strict_types=1);

use App\Actions\Admin\Subscriptions\BuildSubscriptionChurn;
use App\Actions\Admin\Subscriptions\BuildSubscriptionOverview;
use App\Enums\Plan\Slug;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Admin\StripeNetMrr;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Stripe\Subscription as StripeSubscription;

beforeEach(function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $this->admin = User::factory()->create(['email' => 'admin@trypost.test']);
    $this->now = CarbonImmutable::now('UTC')->startOfDay()->addHours(12);
    $this->travelTo($this->now);
    Plan::query()->where('slug', Slug::Socials)->update(['stripe_monthly_price_id' => 'price_socials_monthly', 'stripe_yearly_price_id' => 'price_socials_yearly']);
});

function subscriptionFor(string $name, array $attributes = []): Subscription
{
    $user = User::factory()->create(['name' => $name, 'email' => str($name)->slug().'@example.test']);

    return $user->account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.str()->random(8),
        'stripe_status' => 'active',
        'stripe_price' => 'price_socials_monthly',
        'quantity' => 1,
        ...$attributes,
    ]);
}

function startedAt(Subscription $subscription, CarbonImmutable $at): void
{
    Subscription::query()->whereKey($subscription->id)->toBase()->update(['created_at' => $at]);
}

test('counts subscriptions alive per day, new and canceled ones, churn and list-price MRR', function () {
    $old = subscriptionFor('Old');
    startedAt($old, $this->now->subDays(20));
    $leaver = subscriptionFor('Leaver', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(2)]);
    startedAt($leaver, $this->now->subDays(20));
    $cancelling = subscriptionFor('Cancelling', ['ends_at' => $this->now->addDays(5)]);
    startedAt($cancelling, $this->now->subDays(3));
    subscriptionFor('Legacy', ['stripe_price' => 'price_gone', 'stripe_status' => 'past_due']);
    subscriptionFor('Never paid', ['stripe_status' => 'incomplete']);
    Cache::forever('admin:stripe-price-monthly:price_socials_monthly', 1900);
    Cache::forever('admin:stripe-price-monthly:price_gone', 1200);

    $overview = BuildSubscriptionOverview::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');

    expect($overview['now'])->toMatchArray(['alive' => 3, 'active' => 1, 'past_due' => 1, 'cancelling' => 1])
        ->and($overview['period'])->toMatchArray(['new' => 2, 'canceled' => 1, 'alive_at_start' => 2, 'churn_rate' => 50.0])
        ->and($overview['alive_by_day'][0])->toBe(2)
        ->and($overview['alive_by_day'][6])->toBe(3)
        ->and(array_sum($overview['canceled_by_day']))->toBe(1)
        ->and($overview['mrr'])->toBe(['cents' => 5000, 'subscriptions' => 3, 'unpriced' => 0])
        ->and($overview['at_risk'])->toBe(['past_due_cents' => 1200, 'cancelling_cents' => 1900])
        ->and(collect($overview['plans'])->pluck('name')->all())->toContain('Socials', 'Legacy');
});

test('an offer counts its redemptions and the accounts still subscribed', function () {
    $offer = Offer::factory()->create(['slug' => 'fm1']);
    $stayed = subscriptionFor('Stayed');
    $left = subscriptionFor('Left', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);
    $stayed->owner->offers()->attach($offer->id, ['claimed_at' => $this->now, 'redeemed_at' => $this->now]);
    $left->owner->offers()->attach($offer->id, ['claimed_at' => $this->now, 'redeemed_at' => $this->now]);

    $overview = BuildSubscriptionOverview::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');

    expect($overview['offers'])->toBe([['slug' => 'fm1', 'redeemed' => 2, 'still_subscribed' => 1]]);
});

test('churn lists who left in the period, how long subscriptions lasted and retention by start month', function () {
    $leaver = subscriptionFor('Leaver', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(2)]);
    startedAt($leaver, $this->now->subDays(40));
    subscriptionFor('Stayer');

    $churn = BuildSubscriptionChurn::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');

    expect($churn['recent'])->toHaveCount(1)
        ->and($churn['recent'][0])->toMatchArray(['user_name' => 'Leaver', 'lifetime_days' => 38])
        ->and(collect($churn['lifetimes'])->pluck('count', 'label')->all())->toMatchArray(['1 to 3 months' => 1])
        ->and(collect($churn['cohorts'])->sum('started'))->toBe(2);
});

test('retention counts accounts by their first month, and one cancelling at its period end as lost', function () {
    $stayer = subscriptionFor('Stayer');
    startedAt($stayer, $this->now->subDays(3));
    subscriptionFor('Switcher', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);
    $switcher = User::query()->where('name', 'Switcher')->first()->account;
    $switcher->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_new', 'stripe_status' => 'active', 'stripe_price' => 'price_socials_yearly', 'quantity' => 1]);
    subscriptionFor('Leaving', ['ends_at' => $this->now->addDays(4)]);

    $churn = BuildSubscriptionChurn::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');
    $cohorts = collect($churn['cohorts']);

    expect($cohorts->sum('started'))->toBe(3)
        ->and($cohorts->sum('alive'))->toBe(2);
});

test('the MRR after coupons takes off the discounts still running, a yearly price as a twelfth', function () {
    $now = CarbonImmutable::now('UTC');
    $subscription = StripeSubscription::constructFrom([
        'id' => 'sub_1',
        'object' => 'subscription',
        'items' => ['object' => 'list', 'data' => [
            ['id' => 'si_1', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => 'price_y', 'object' => 'price', 'unit_amount' => 19000, 'recurring' => ['interval' => 'year', 'interval_count' => 1]]],
        ]],
        'discounts' => [
            ['id' => 'di_1', 'object' => 'discount', 'end' => null, 'coupon' => ['id' => 'HALF', 'object' => 'coupon', 'percent_off' => 50]],
            ['id' => 'di_2', 'object' => 'discount', 'end' => $now->subDay()->timestamp, 'coupon' => ['id' => 'OLD', 'object' => 'coupon', 'amount_off' => 1000]],
        ],
    ]);

    $monthly = StripeNetMrr::monthly($subscription, $now);

    expect(round($monthly['list'], 2))->toBe(round(19000 / 12, 2))
        ->and(round($monthly['net'], 2))->toBe(round(9500 / 12, 2))
        ->and($monthly['discounted'])->toBeTrue();
});

test('the subscriptions table filters by where a subscription stands and searches its owner', function () {
    subscriptionFor('Active Ann');
    subscriptionFor('Cancelling Carl', ['ends_at' => $this->now->addDays(3)]);
    subscriptionFor('Gone Gina', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.subscriptions.index', ['tab' => 'subscriptions', 'status' => 'cancelling']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/billing/subscriptions/Index')
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.user_name', 'Cancelling Carl')
            ->where('subscriptions.data.0.state', 'cancelling')
            ->where('subscriptions.data.0.plan.name', 'Socials'),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.billing.subscriptions.index', ['search' => 'gina']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.state', 'canceled'),
        );
});

test('every subscription is in exactly one group, so the groups add up', function () {
    subscriptionFor('Active Ann');
    subscriptionFor('Late Leo', ['stripe_status' => 'past_due', 'ends_at' => $this->now->addDays(3)]);
    subscriptionFor('Late Lia', ['stripe_status' => 'past_due']);
    subscriptionFor('Gone Gina', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);

    $now = BuildSubscriptionOverview::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC')['now'];

    expect($now)->toMatchArray(['alive' => 3, 'active' => 1, 'past_due' => 1, 'cancelling' => 1])
        ->and($now['active'] + $now['past_due'] + $now['trialing'] + $now['cancelling'])->toBe($now['alive']);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.subscriptions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('statusCounts', ['all' => 4, 'active' => 1, 'cancelling' => 1, 'past_due' => 1, 'trialing' => 0, 'canceled' => 1]),
        );
});

test('churn sums up the period and draws the daily cancellations and the rolling rate', function () {
    $leaver = subscriptionFor('Leaver', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(2)]);
    startedAt($leaver, $this->now->subDays(40));
    $stayer = subscriptionFor('Stayer');
    startedAt($stayer, $this->now->subDays(40));

    $churn = BuildSubscriptionChurn::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');

    expect($churn['summary'])->toMatchArray(['canceled' => 1, 'churn_rate' => 50.0, 'average_lifetime_days' => 38])
        ->and(array_sum($churn['canceled_by_day']))->toBe(1)
        ->and(end($churn['churn_rate_by_day']))->toBe(50.0);
});

test('a user who is not an admin cannot see subscriptions', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.billing.subscriptions.index'))
        ->assertForbidden();
});

test('the overview carries the period before, to compare with', function () {
    $before = subscriptionFor('Before');
    startedAt($before, $this->now->subDays(10));
    $leftBefore = subscriptionFor('Left before', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(9)]);
    startedAt($leftBefore, $this->now->subDays(20));
    Cache::forever('admin:stripe-price-monthly:price_socials_monthly', 1900);

    $previous = BuildSubscriptionOverview::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC')['previous'];

    expect($previous)->toMatchArray(['new' => 1, 'canceled' => 1, 'churn_rate' => 100.0, 'mrr_cents' => 1900]);
});
