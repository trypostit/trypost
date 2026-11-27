<?php

declare(strict_types=1);

use App\Http\Requests\Admin\Insights\IndexSignupInsightsRequest;
use App\Http\Requests\Admin\Network\IndexNetworkHealthRequest;
use App\Http\Requests\Admin\Post\IndexPostStatsRequest;
use App\Http\Requests\Admin\Subscription\IndexSubscriptionRequest;
use App\Http\Requests\Admin\User\IndexUserInsightsRequest;

beforeEach(function () {
    $this->admin = backofficeAdmin(['timezone' => 'Asia/Tokyo']);
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
