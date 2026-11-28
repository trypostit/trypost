<?php

declare(strict_types=1);

use App\Http\Requests\Admin\Insights\IndexSignupInsightsRequest;
use App\Http\Requests\Admin\Network\IndexNetworkHealthRequest;
use App\Http\Requests\Admin\Post\IndexPostStatsRequest;
use App\Http\Requests\Admin\Quota\IndexQuotaRequest;
use App\Http\Requests\Admin\Subscription\IndexSubscriptionRequest;
use App\Http\Requests\Admin\User\IndexUserInsightsRequest;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = admin(['timezone' => 'Asia/Tokyo']);
    $this->travelTo(now('UTC')->startOfDay()->addHours(23));
});

dataset('range pages', [
    'publishing' => ['admin.posts.index', IndexPostStatsRequest::MAX_DAYS_BACK],
    'networks' => ['admin.networks.index', IndexNetworkHealthRequest::MAX_DAYS_BACK],
    'subscriptions' => ['admin.billing.subscriptions.index', IndexSubscriptionRequest::MAX_DAYS_BACK],
    'signups' => ['admin.billing.insights.index', IndexSignupInsightsRequest::MAX_DAYS_BACK],
    'user insights' => ['admin.users.insights.index', IndexUserInsightsRequest::MAX_DAYS_BACK],
]);

test('a custom range may end on the admin\'s own today, ahead of UTC', function (string $route) {
    $today = now('Asia/Tokyo')->toDateString();

    expect($today)->not->toBe(now('UTC')->toDateString());

    $this->actingAs($this->admin)
        ->get(route($route, ['range' => 'custom', 'start' => now('Asia/Tokyo')->subDays(6)->toDateString(), 'end' => $today]))
        ->assertOk()
        ->assertSessionHasNoErrors();
})->with('range pages');

test('a custom range cannot end after the admin\'s today', function (string $route) {
    $this->actingAs($this->admin)
        ->get(route($route, ['range' => 'custom', 'start' => now('Asia/Tokyo')->toDateString(), 'end' => now('Asia/Tokyo')->addDay()->toDateString()]))
        ->assertSessionHasErrors('end');
})->with('range pages');

test('a custom range cannot start further back than the page keeps', function (string $route, int $maxDaysBack) {
    $this->actingAs($this->admin)
        ->get(route($route, ['range' => 'custom', 'start' => now('Asia/Tokyo')->subDays($maxDaysBack + 1)->toDateString(), 'end' => now('Asia/Tokyo')->toDateString()]))
        ->assertSessionHasErrors('start');
})->with('range pages');

test('a custom range without its days is refused on every range page', function (string $route) {
    $this->actingAs($this->admin)
        ->get(route($route, ['range' => 'custom']))
        ->assertSessionHasErrors(['start', 'end']);
})->with('range pages');

test('the quotas page offers no custom range at all', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.quotas.index', ['range' => 'custom']))
        ->assertSessionHasErrors('range');
});

test('the month to date is not a backoffice period', function (string $route) {
    $this->actingAs($this->admin)
        ->get(route($route, ['range' => 'mtd']))
        ->assertSessionHasErrors('range');
})->with(['admin.posts.index', 'admin.networks.index', 'admin.billing.subscriptions.index', 'admin.billing.insights.index', 'admin.users.insights.index', 'admin.quotas.index']);

test('a custom range may start exactly as far back as the page keeps', function (string $route, int $maxDaysBack) {
    $this->actingAs($this->admin)
        ->get(route($route, ['range' => 'custom', 'start' => now('Asia/Tokyo')->subDays($maxDaysBack)->toDateString(), 'end' => now('Asia/Tokyo')->toDateString()]))
        ->assertOk()
        ->assertSessionHasNoErrors();
})->with('range pages');

test('the quotas page counts X\'s UTC days, whatever the admin\'s zone', function () {
    $today = now('UTC')->toDateString();

    expect(now('Asia/Tokyo')->toDateString())->not->toBe($today);

    $this->actingAs($this->admin)
        ->get(route('admin.quotas.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('range.end', $today)
            ->where('bounds.max', $today)
            ->where('bounds.min', now('UTC')->subDays(IndexQuotaRequest::MAX_DAYS_BACK)->toDateString()),
        );
});
