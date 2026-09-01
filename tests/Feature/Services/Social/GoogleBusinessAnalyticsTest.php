<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessAnalytics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->socialAccount = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
        'status' => AccountStatus::Connected,
        'is_active' => true,
    ]);
    $this->analytics = new GoogleBusinessAnalytics;
});

test('fetches the universal performance metrics for the account location', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response([
            'multiDailyMetricTimeSeries' => [
                [
                    'dailyMetricTimeSeries' => [
                        [
                            'dailyMetric' => 'WEBSITE_CLICKS',
                            'timeSeries' => ['datedValues' => [['value' => '10'], ['value' => '5']]],
                        ],
                        [
                            'dailyMetric' => 'CALL_CLICKS',
                            'timeSeries' => ['datedValues' => [['value' => '3']]],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $metrics = $this->analytics->getMetrics($this->socialAccount);

    $labels = array_column($metrics, 'label');
    expect($labels)->toHaveCount(8);

    $websiteClicks = collect($metrics)->firstWhere('label', __('analytics.metrics.website_clicks'));
    expect($websiteClicks['value'])->toBe(15);
});

test('requests the short location resource name with repeated dailyMetrics params', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response(['multiDailyMetricTimeSeries' => []], 200),
    ]);

    $this->analytics->getMetrics($this->socialAccount);

    $expectedUrl = config('trypost.platforms.google_business.performance_api')
        ."/{$this->socialAccount->meta['location_name']}:fetchMultiDailyMetricsTimeSeries";

    Http::assertSent(function ($request) use ($expectedUrl) {
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        return Str::before($request->url(), '?') === $expectedUrl
            && Str::contains($query, 'dailyMetrics=WEBSITE_CLICKS')
            && Str::contains($query, 'dailyMetrics=CALL_CLICKS')
            && Str::contains($query, 'dailyMetrics=BUSINESS_IMPRESSIONS_MOBILE_MAPS')
            && ! Str::contains($query, 'dailyMetrics%5B')
            && ! Str::contains($query, 'dailyMetrics[');
    });
});

test('returns empty array when the account has no location', function () {
    Http::fake();

    $this->socialAccount->update(['meta' => ['location_id' => 'accounts/1/locations/2']]);

    expect($this->analytics->getMetrics($this->socialAccount->fresh()))->toBe([]);

    Http::assertNothingSent();
});

test('google business is listed on the analytics page', function () {
    $response = $this->actingAs($this->user)->get(route('app.analytics'));

    $response->assertOk();

    $accounts = $response->original->getData()['page']['props']['accounts'];

    expect(collect($accounts)->firstWhere('platform', Platform::GoogleBusiness->value))->not->toBeNull();
});

test('the analytics show endpoint returns google business metrics', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response([
            'multiDailyMetricTimeSeries' => [
                [
                    'dailyMetricTimeSeries' => [
                        [
                            'dailyMetric' => 'WEBSITE_CLICKS',
                            'timeSeries' => ['datedValues' => [['value' => '7']]],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.analytics.show', $this->socialAccount));

    $response->assertOk()->assertJsonCount(8, 'metrics');

    $metrics = $response->json('metrics');

    expect(collect($metrics)->pluck('label')->filter())->toHaveCount(8)
        ->and(collect($metrics)->firstWhere('label', __('analytics.metrics.website_clicks'))['value'])->toBe(7);
});

test('caches the metrics so a repeated call within the window does not hit the api again', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response([
            'multiDailyMetricTimeSeries' => [
                [
                    'dailyMetricTimeSeries' => [
                        [
                            'dailyMetric' => 'WEBSITE_CLICKS',
                            'timeSeries' => ['datedValues' => [['value' => '9']]],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $first = $this->analytics->getMetrics($this->socialAccount);
    $second = $this->analytics->getMetrics($this->socialAccount);

    expect($second)->toBe($first);

    Http::assertSentCount(1);
});

test('returns empty array on api failure', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response([], 500),
    ]);

    expect($this->analytics->getMetrics($this->socialAccount))->toBe([]);
});

test('it reports search impressions, not just maps impressions', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response([
            'multiDailyMetricTimeSeries' => [[
                'dailyMetricTimeSeries' => [
                    ['dailyMetric' => 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'timeSeries' => ['datedValues' => [['value' => '40']]]],
                    ['dailyMetric' => 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'timeSeries' => ['datedValues' => [['value' => '60']]]],
                    ['dailyMetric' => 'BUSINESS_CONVERSATIONS', 'timeSeries' => ['datedValues' => [['value' => '7']]]],
                ],
            ]],
        ], 200),
    ]);

    $metrics = collect($this->analytics->getMetrics($this->socialAccount));

    expect($metrics->firstWhere('label', __('analytics.metrics.desktop_search_impressions'))['value'])->toBe(40)
        ->and($metrics->firstWhere('label', __('analytics.metrics.mobile_search_impressions'))['value'])->toBe(60)
        ->and($metrics->firstWhere('label', __('analytics.metrics.conversations'))['value'])->toBe(7);
});

test('it hides the food and booking metrics that only some business types ever report', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response([
            'multiDailyMetricTimeSeries' => [[
                'dailyMetricTimeSeries' => [
                    ['dailyMetric' => 'BUSINESS_FOOD_ORDERS', 'timeSeries' => ['datedValues' => [['value' => '0']]]],
                    ['dailyMetric' => 'BUSINESS_BOOKINGS', 'timeSeries' => ['datedValues' => [['value' => '4']]]],
                ],
            ]],
        ], 200),
    ]);

    $labels = array_column($this->analytics->getMetrics($this->socialAccount), 'label');

    expect($labels)->toContain(__('analytics.metrics.bookings'))
        ->and($labels)->not->toContain(__('analytics.metrics.food_orders'))
        ->and($labels)->not->toContain(__('analytics.metrics.food_menu_clicks'));
});

test('it reports the search keywords people used to find the business', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*/searchkeywords/*' => Http::response([
            'searchKeywordsCounts' => [
                ['searchKeyword' => 'coffee near me', 'insightsValue' => ['value' => '320']],
                ['searchKeyword' => 'best espresso', 'insightsValue' => ['threshold' => '15']],
            ],
        ]),
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response(['multiDailyMetricTimeSeries' => []]),
    ]);

    $keywords = $this->analytics->getSearchKeywords($this->socialAccount);

    // A suppressed term reports a privacy threshold, not a count — saying 15
    // where Google said "fewer than 15" would be inventing a number.
    expect($keywords)->toBe([
        ['keyword' => 'coffee near me', 'value' => 320, 'estimated' => false],
        ['keyword' => 'best espresso', 'value' => 15, 'estimated' => true],
    ]);
});

test('it follows the keyword pages and asks for whole months', function () {
    Http::fakeSequence()
        ->push(['searchKeywordsCounts' => [['searchKeyword' => 'a', 'insightsValue' => ['value' => '2']]], 'nextPageToken' => 'page-2'])
        ->push(['searchKeywordsCounts' => [['searchKeyword' => 'b', 'insightsValue' => ['value' => '1']]]]);

    $keywords = $this->analytics->getSearchKeywords(
        $this->socialAccount,
        now()->setDate(2026, 6, 14),
        now()->setDate(2026, 8, 3),
    );

    expect(array_column($keywords, 'keyword'))->toBe(['a', 'b']);

    // parse_str() rewrites dots in keys to underscores, so match the raw query.
    Http::assertSent(function ($request) {
        $query = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

        return str_contains($request->url(), '/searchkeywords/impressions/monthly')
            && str_contains($query, 'monthlyRange.start_month.year=2026')
            && str_contains($query, 'monthlyRange.start_month.month=6')
            && str_contains($query, 'monthlyRange.end_month.month=8');
    });
});

test('the analytics endpoint returns the search keywords alongside the metrics', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*/searchkeywords/*' => Http::response([
            'searchKeywordsCounts' => [['searchKeyword' => 'coffee near me', 'insightsValue' => ['value' => '320']]],
        ]),
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response(['multiDailyMetricTimeSeries' => []]),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.analytics.show', $this->socialAccount));

    $response->assertOk()->assertJsonPath('keywords.0.keyword', 'coffee near me');
});
