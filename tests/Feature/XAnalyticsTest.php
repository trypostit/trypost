<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\XAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->account = SocialAccount::factory()->x()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'platform_user_id' => '4242',
        'token_expires_at' => now()->addHours(2),
    ]);
    $this->api = config('trypost.platforms.x.api');
});

test('metrics come from the timeline itself instead of a second lookup of the same posts', function () {
    Http::fake([
        $this->api.'/users/4242/tweets*' => Http::response([
            'data' => [
                ['id' => '1', 'public_metrics' => ['impression_count' => 100, 'like_count' => 10, 'retweet_count' => 1, 'reply_count' => 2, 'quote_count' => 3, 'bookmark_count' => 4]],
                ['id' => '2', 'public_metrics' => ['impression_count' => 200, 'like_count' => 20, 'retweet_count' => 2, 'reply_count' => 4, 'quote_count' => 6, 'bookmark_count' => 8]],
            ],
            'meta' => [],
        ], 200),
    ]);

    $metrics = app(XAnalytics::class)->getMetrics($this->account);

    // Every post returned is a billed Post read. Re-reading the same ids from
    // /2/tweets buys nothing the timeline could not have returned.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/tweets?')
        || preg_match('#/tweets\?ids=#', $request->url()) === 1);

    expect(collect($metrics)->firstWhere('label', __('analytics.metrics.impressions'))['value'])->toBe(300);
    expect(collect($metrics)->firstWhere('label', __('analytics.metrics.likes'))['value'])->toBe(30);
});

test('the timeline request asks for public_metrics', function () {
    Http::fake([
        $this->api.'/users/4242/tweets*' => Http::response(['data' => [], 'meta' => []], 200),
    ]);

    app(XAnalytics::class)->getMetrics($this->account);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'tweet.fields=public_metrics'));
});

test('metrics accumulate across paginated timeline pages', function () {
    Http::fake([
        $this->api.'/users/4242/tweets*' => Http::sequence()
            ->push([
                'data' => [['id' => '1', 'public_metrics' => ['impression_count' => 100, 'like_count' => 1, 'retweet_count' => 0, 'reply_count' => 0, 'quote_count' => 0, 'bookmark_count' => 0]]],
                'meta' => ['next_token' => 'page2'],
            ], 200)
            ->push([
                'data' => [['id' => '2', 'public_metrics' => ['impression_count' => 50, 'like_count' => 2, 'retweet_count' => 0, 'reply_count' => 0, 'quote_count' => 0, 'bookmark_count' => 0]]],
                'meta' => [],
            ], 200),
    ]);

    $metrics = app(XAnalytics::class)->getMetrics($this->account);

    expect(collect($metrics)->firstWhere('label', __('analytics.metrics.impressions'))['value'])->toBe(150);
    expect(collect($metrics)->firstWhere('label', __('analytics.metrics.likes'))['value'])->toBe(3);
});

test('a page that fails mid-pagination keeps the totals collected so far', function () {
    Http::fake([
        $this->api.'/users/4242/tweets*' => Http::sequence()
            ->push([
                'data' => [['id' => '1', 'public_metrics' => ['impression_count' => 100, 'like_count' => 5, 'retweet_count' => 0, 'reply_count' => 0, 'quote_count' => 0, 'bookmark_count' => 0]]],
                'meta' => ['next_token' => 'page2'],
            ], 200)
            ->push(['title' => 'Internal Error'], 500),
    ]);

    $metrics = app(XAnalytics::class)->getMetrics($this->account);

    // Partial data beats an exception on a dashboard the user is looking at.
    expect(collect($metrics)->firstWhere('label', __('analytics.metrics.impressions'))['value'])->toBe(100);
    expect(collect($metrics)->firstWhere('label', __('analytics.metrics.likes'))['value'])->toBe(5);
});

test('a post returned without public_metrics counts as zero rather than erroring', function () {
    Http::fake([
        $this->api.'/users/4242/tweets*' => Http::response([
            'data' => [
                ['id' => '1'],
                ['id' => '2', 'public_metrics' => ['impression_count' => 7, 'like_count' => 1, 'retweet_count' => 0, 'reply_count' => 0, 'quote_count' => 0, 'bookmark_count' => 0]],
            ],
            'meta' => [],
        ], 200),
    ]);

    $metrics = app(XAnalytics::class)->getMetrics($this->account);

    expect(collect($metrics)->firstWhere('label', __('analytics.metrics.impressions'))['value'])->toBe(7);
});

test('an account that posted nothing in the range returns no metrics at all', function () {
    Http::fake([
        $this->api.'/users/4242/tweets*' => Http::response(['data' => [], 'meta' => []], 200),
    ]);

    expect(app(XAnalytics::class)->getMetrics($this->account))->toBe([]);
});
