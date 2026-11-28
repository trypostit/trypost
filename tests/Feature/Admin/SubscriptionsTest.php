<?php

declare(strict_types=1);

use App\Actions\Admin\Subscriptions\BuildSubscriptionChurn;
use App\Actions\Admin\Subscriptions\BuildSubscriptionOverview;
use App\Actions\Admin\Subscriptions\ListSubscriptions;
use App\Dto\Analytics\DateRange;
use App\Enums\Plan\Slug;
use App\Http\Resources\Admin\AdminSubscriptionResource;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Admin\StripeNetMrr;
use App\Support\Admin\SubscriptionHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Stripe\ApiRequestor;
use Stripe\Subscription as StripeSubscription;

beforeEach(function () {
    $this->admin = backofficeAdmin();
    $this->now = CarbonImmutable::now('UTC')->startOfDay()->addHours(12);
    $this->travelTo($this->now);
    Plan::query()->where('slug', Slug::Socials)->update(['stripe_monthly_price_id' => 'price_socials_monthly', 'stripe_yearly_price_id' => 'price_socials_yearly']);
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

/** A subscription on the Socials monthly price of a new owner with that name. */
function adminSubscriptionFor(string $name, array $attributes = []): Subscription
{
    $slug = str($name)->slug();
    $user = User::factory()->create(['name' => $name, 'email' => "{$slug}@example.test"]);

    return subscribeAccount($user->account, ['stripe_price' => 'price_socials_monthly', ...$attributes]);
}

test('counts subscriptions alive per day, new and canceled ones, churn and list-price MRR', function () {
    $old = adminSubscriptionFor('Old');
    adminBackdateSubscription($old, $this->now->subDays(20));
    $leaver = adminSubscriptionFor('Leaver', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(2)]);
    adminBackdateSubscription($leaver, $this->now->subDays(20));
    $cancelling = adminSubscriptionFor('Cancelling', ['ends_at' => $this->now->addDays(5)]);
    adminBackdateSubscription($cancelling, $this->now->subDays(3));
    adminSubscriptionFor('Legacy', ['stripe_price' => 'price_gone', 'stripe_status' => 'past_due']);
    adminSubscriptionFor('Never paid', ['stripe_status' => 'incomplete']);
    fakeStripeReads(['price_socials_monthly' => [1900, 'month'], 'price_gone' => [1200, 'month']]);

    $overview = BuildSubscriptionOverview::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read());

    expect($overview['now'])->toMatchArray(['alive' => 3, 'active' => 1, 'past_due' => 1, 'cancelling' => 1])
        ->and($overview['period'])->toMatchArray(['new' => 2, 'canceled' => 1, 'alive_at_start' => 2, 'churn_rate' => 50.0])
        ->and($overview['alive_by_day'][0])->toBe(2)
        ->and($overview['alive_by_day'][6])->toBe(3)
        ->and(array_sum($overview['canceled_by_day']))->toBe(1)
        ->and($overview['mrr'])->toBe(['cents' => 5000, 'unpriced' => 0])
        ->and($overview['at_risk'])->toBe(['past_due_cents' => 1200, 'cancelling_cents' => 1900])
        ->and(collect($overview['plans'])->pluck('name')->all())->toContain('Socials', 'Legacy');
});

test('an offer counts its redemptions and the accounts still subscribed', function () {
    $offer = Offer::factory()->create(['slug' => 'fm1']);
    $stayed = adminSubscriptionFor('Stayed');
    $left = adminSubscriptionFor('Left', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);
    $stayed->owner->offers()->attach($offer->id, ['claimed_at' => $this->now, 'redeemed_at' => $this->now]);
    $left->owner->offers()->attach($offer->id, ['claimed_at' => $this->now, 'redeemed_at' => $this->now]);

    $overview = BuildSubscriptionOverview::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read());

    expect($overview['offers'])->toBe([['slug' => 'fm1', 'redeemed' => 2, 'still_subscribed' => 1]]);
});

test('churn lists who left in the period, how long subscriptions lasted and retention by start month', function () {
    $leaver = adminSubscriptionFor('Leaver', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(2)]);
    adminBackdateSubscription($leaver, $this->now->subDays(40));
    adminSubscriptionFor('Stayer');

    $churn = BuildSubscriptionChurn::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read());

    expect($churn['recent'])->toHaveCount(1)
        ->and($churn['recent'][0])->toMatchArray(['user_name' => 'Leaver', 'lifetime_days' => 38])
        ->and(collect($churn['lifetimes'])->pluck('count', 'label')->all())->toMatchArray(['1 to 3 months' => 1])
        ->and(collect($churn['cohorts'])->sum('started'))->toBe(2);
});

test('retention counts accounts by their first month, and one cancelling at its period end as lost', function () {
    $stayer = adminSubscriptionFor('Stayer');
    adminBackdateSubscription($stayer, $this->now->subDays(3));
    adminSubscriptionFor('Switcher', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);
    $switcher = User::query()->where('name', 'Switcher')->first()->account;
    subscribeAccount($switcher, ['stripe_price' => 'price_socials_yearly']);
    adminSubscriptionFor('Leaving', ['ends_at' => $this->now->addDays(4)]);

    $churn = BuildSubscriptionChurn::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read());
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
        ->and(round($monthly['net'], 2))->toBe(round(9500 / 12, 2));
});

test('the subscriptions table filters by where a subscription stands and searches its owner', function () {
    adminSubscriptionFor('Active Ann');
    adminSubscriptionFor('Cancelling Carl', ['ends_at' => $this->now->addDays(3)]);
    adminSubscriptionFor('Gone Gina', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);

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

test('a subscription is in All and at most one other group; an unpaid one without an end is in All only', function () {
    adminSubscriptionFor('Active Ann');
    adminSubscriptionFor('Unpaid Uma', ['stripe_status' => 'unpaid']);
    adminSubscriptionFor('Late Leo', ['stripe_status' => 'past_due', 'ends_at' => $this->now->addDays(3)]);
    adminSubscriptionFor('Late Lia', ['stripe_status' => 'past_due']);
    adminSubscriptionFor('Gone Gina', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);

    $now = BuildSubscriptionOverview::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read())['now'];

    expect($now)->toMatchArray(['alive' => 4, 'active' => 1, 'past_due' => 1, 'cancelling' => 1])
        ->and($now['active'] + $now['past_due'] + $now['trialing'] + $now['cancelling'])->toBe($now['alive'] - 1);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.subscriptions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('statusCounts', ['all' => 5, 'active' => 1, 'cancelling' => 1, 'past_due' => 1, 'trialing' => 0, 'canceled' => 1]),
        );
});

test('churn sums up the period and draws the daily cancellations and the rolling rate', function () {
    $leaver = adminSubscriptionFor('Leaver', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(2)]);
    adminBackdateSubscription($leaver, $this->now->subDays(40));
    $stayer = adminSubscriptionFor('Stayer');
    adminBackdateSubscription($stayer, $this->now->subDays(40));

    $churn = BuildSubscriptionChurn::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read());

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
    $before = adminSubscriptionFor('Before');
    adminBackdateSubscription($before, $this->now->subDays(10));
    $leftBefore = adminSubscriptionFor('Left before', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(9)]);
    adminBackdateSubscription($leftBefore, $this->now->subDays(20));
    fakeStripeReads(['price_socials_monthly' => [1900, 'month']]);

    $previous = BuildSubscriptionOverview::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read())['previous'];

    expect($previous)->toMatchArray(['new' => 1, 'canceled' => 1, 'churn_rate' => 100.0, 'mrr_cents' => 1900]);
});

test('the MRR after coupons takes a fixed amount off once per invoice, however many items it has', function () {
    $now = CarbonImmutable::now('UTC');
    $subscription = StripeSubscription::constructFrom([
        'id' => 'sub_1',
        'object' => 'subscription',
        'items' => ['object' => 'list', 'data' => [
            ['id' => 'si_1', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => 'price_a', 'object' => 'price', 'unit_amount' => 1900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]],
            ['id' => 'si_2', 'object' => 'subscription_item', 'quantity' => 2, 'price' => ['id' => 'price_b', 'object' => 'price', 'unit_amount' => 500, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]],
        ]],
        'discounts' => [
            ['id' => 'di_1', 'object' => 'discount', 'end' => null, 'coupon' => ['id' => 'TEN', 'object' => 'coupon', 'amount_off' => 1000]],
        ],
    ]);

    $monthly = StripeNetMrr::monthly($subscription, $now);

    expect(round($monthly['list'], 2))->toBe(2900.0)
        ->and(round($monthly['net'], 2))->toBe(1900.0);
});

test('the MRR after coupons keeps a failed Stripe read a minute, so the page does not ask again at once', function () {
    $stripe = fakeStripeReads(down: true);

    expect(StripeNetMrr::read())->toHaveKey('error')
        ->and(StripeNetMrr::read())->toHaveKey('error')
        ->and($stripe->requests)->toBe(1);

    $this->travel(StripeNetMrr::ERROR_CACHE_SECONDS + 1)->seconds();
    StripeNetMrr::read();

    expect($stripe->requests)->toBe(2);
});

test('the MRR after coupons reads every paying subscription from Stripe', function () {
    $item = fn (int $amount): array => ['object' => 'list', 'data' => [
        ['id' => "si_{$amount}", 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => "price_{$amount}", 'object' => 'price', 'unit_amount' => $amount, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]],
    ]];
    fakeStripeReads(subscriptions: [
        ['id' => 'sub_a', 'object' => 'subscription', 'status' => 'active', 'items' => $item(1900), 'discounts' => []],
        ['id' => 'sub_p', 'object' => 'subscription', 'status' => 'past_due', 'items' => $item(9900), 'discounts' => [
            ['id' => 'di_1', 'object' => 'discount', 'end' => null, 'coupon' => ['id' => 'HALF', 'object' => 'coupon', 'percent_off' => 50]],
        ]],
    ]);

    expect(StripeNetMrr::read())->toBe(['cents' => 6850]);
});

test('the overview counts a subscription on the day it started in the viewer\'s zone', function () {
    $today = $this->now->startOfDay();
    adminBackdateSubscription(adminSubscriptionFor('Night owl'), $today->addHour());

    $saoPaulo = BuildSubscriptionOverview::execute(DateRange::fromDates($today->subDay()->toDateString(), $today->toDateString(), 'America/Sao_Paulo'), SubscriptionHistory::read());
    $utc = BuildSubscriptionOverview::execute(DateRange::fromDates($today->subDay()->toDateString(), $today->toDateString(), 'UTC'), SubscriptionHistory::read());

    expect($saoPaulo['new_by_day'])->toBe([1, 0])
        ->and($utc['new_by_day'])->toBe([0, 1]);
});

test('a subscription lists only the offers its account redeemed, not those it only claimed', function () {
    $redeemed = Offer::factory()->create(['slug' => 'fm1']);
    $claimed = Offer::factory()->create(['slug' => 'bf26']);
    $left = adminSubscriptionFor('Left', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);
    $left->owner->offers()->attach($redeemed->id, ['claimed_at' => $this->now->subMonth(), 'redeemed_at' => $this->now->subMonth()]);
    $left->owner->offers()->attach($claimed->id, ['claimed_at' => $this->now->subWeek(), 'redeemed_at' => null]);

    $churn = BuildSubscriptionChurn::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read());

    expect($churn['recent'][0]['offers'])->toBe(['fm1']);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.subscriptions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('subscriptions.data.0.offers', ['fm1']));
});

test('a canceled subscription in the churn list reads like a row of the subscriptions table, with how long it lasted', function () {
    $leaver = adminSubscriptionFor('Leaver', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDays(2)]);
    adminBackdateSubscription($leaver, $this->now->subDays(40));

    $row = BuildSubscriptionChurn::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'), SubscriptionHistory::read())['recent'][0];

    expect($row)->toMatchArray([
        ...AdminSubscriptionResource::make($leaver->fresh())->resolve(),
        'state' => 'canceled',
        'ends_at' => $this->now->subDays(2)->toIso8601String(),
        'lifetime_days' => 38,
    ]);
});

test('each status filter lists exactly the subscriptions its count says, each in that state', function (string $status, int $count, ?string $state) {
    adminSubscriptionFor('Active Ann');
    adminSubscriptionFor('Trial Tom', ['stripe_status' => 'trialing']);
    adminSubscriptionFor('Late Lia', ['stripe_status' => 'past_due']);
    adminSubscriptionFor('Late Leo', ['stripe_status' => 'past_due', 'ends_at' => $this->now->addDays(3)]);
    adminSubscriptionFor('Cancelling Carl', ['ends_at' => $this->now->addDays(3)]);
    adminSubscriptionFor('Gone Gina', ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);
    adminSubscriptionFor('Unpaid Uma', ['stripe_status' => 'unpaid']);
    adminSubscriptionFor('Never Ned', ['stripe_status' => 'incomplete']);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.subscriptions.index', ['status' => $status]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("statusCounts.{$status}", $count)
            ->has('subscriptions.data', $count)
            ->where('subscriptions.data', fn (Collection $rows): bool => $state === null || $rows->every(fn (array $row): bool => $row['state'] === $state)),
        );
})->with([
    'all' => ['all', 7, null],
    'active' => ['active', 1, 'active'],
    'cancelling, a past due one with an end included' => ['cancelling', 2, 'cancelling'],
    'past due without an end only' => ['past_due', 1, 'past_due'],
    'trialing' => ['trialing', 1, 'trialing'],
    'canceled' => ['canceled', 1, 'canceled'],
]);

test('the status counts follow the search, and the filters keep every choice', function () {
    adminSubscriptionFor('Active Ann');
    adminSubscriptionFor('Cancelling Carl', ['ends_at' => $this->now->addDays(3)]);
    $today = now($this->admin->timezone);
    $filters = ['range' => 'custom', 'start' => $today->subDays(10)->toDateString(), 'end' => $today->toDateString(), 'status' => 'cancelling', 'search' => 'carl'];

    expect(ListSubscriptions::statusCounts('carl'))->toBe(['all' => 1, 'active' => 0, 'cancelling' => 1, 'past_due' => 0, 'trialing' => 0, 'canceled' => 0]);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.subscriptions.index', $filters))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters', $filters)
            ->where('statusCounts.all', 1)
            ->where('statusCounts.active', 0),
        );
});

test('a subscription in no status group sends no state, and its Stripe status apart', function () {
    adminSubscriptionFor('Unpaid Uma', ['stripe_status' => 'unpaid']);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.subscriptions.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('subscriptions.data.0.state', null)
            ->where('subscriptions.data.0.stripe_status', 'unpaid'),
        );
});
