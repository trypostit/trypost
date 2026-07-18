<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LinkedInPageAnalytics
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        // Versioned API (`/rest/`) is the only one that honours the
        // LinkedIn-Version header and the current analytics schemas.
        // The legacy `/v2/` path rejects newer parameter formats with
        // "Parameter 'timeIntervals' is invalid".
        $this->baseUrl = config('trypost.platforms.linkedin-page.api').'/rest';
    }

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(7);
        $until ??= now();

        $cacheKey = "analytics:linkedin-page:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}";
        $cacheTtl = app()->isProduction() ? 3600 : 1;

        return Cache::remember($cacheKey, $cacheTtl, function () use ($account, $since, $until) {
            return $this->fetchMetricsFromApi($account, $since, $until);
        });
    }

    public function fetchPostMetrics(PostPlatform $postPlatform): array
    {
        $account = $postPlatform->socialAccount;

        if (! $account || ! $postPlatform->platform_post_id) {
            return ['unsupported' => true, 'reason' => 'missing_post_id'];
        }

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        // platform_post_id is the share URN (e.g., "urn:li:share:12345").
        $shareUrn = urlencode($postPlatform->platform_post_id);

        $response = $this->socialHttp()
            ->withToken($account->access_token)
            ->get("{$this->baseUrl}/socialActions/{$shareUrn}");

        if ($response->failed()) {
            Log::warning('LinkedIn Page post metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        $data = $response->json();

        return [
            ['label' => __('analytics.metrics.likes'), 'value' => (int) data_get($data, 'likesSummary.totalLikes', 0)],
            ['label' => __('analytics.metrics.comments'), 'value' => (int) data_get($data, 'commentsSummary.aggregatedTotalComments', 0)],
        ];
    }

    private function fetchMetricsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $orgUrn = "urn:li:organization:{$account->platform_user_id}";
        // LinkedIn requires both endpoints of the timeRange to be at midnight
        // UTC (00:00:00.000). endOfDay() produces 23:59:59.999 which the API
        // silently rejects with "Parameter 'timeIntervals' is invalid".
        $startMs = $since->copy()->utc()->startOfDay()->getTimestampMs();
        $endMs = $until->copy()->utc()->startOfDay()->addDay()->getTimestampMs();
        $timeInterval = "(timeRange:(start:{$startMs},end:{$endMs}),timeGranularityType:DAY)";

        $metrics = [];

        // Page statistics (page views)
        $pageStats = $this->fetchPageStatistics($orgUrn, $timeInterval);
        $metrics = array_merge($metrics, $pageStats);

        // Follower statistics
        $followerStats = $this->fetchFollowerStatistics($orgUrn, $timeInterval);
        $metrics = array_merge($metrics, $followerStats);

        // Share statistics (engagement)
        $shareStats = $this->fetchShareStatistics($orgUrn, $timeInterval);
        $metrics = array_merge($metrics, $shareStats);

        return $metrics;
    }

    private function fetchPageStatistics(string $orgUrn, string $timeInterval): array
    {
        $org = rawurlencode($orgUrn);
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/organizationPageStatistics?q=organization&organization={$org}&timeIntervals={$timeInterval}");

        if ($response->failed()) {
            Log::warning('LinkedIn page statistics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $elements = data_get($response->json(), 'elements', []);
        $totalPageViews = 0;

        foreach ($elements as $element) {
            $totalPageViews += data_get($element, 'totalPageStatistics.views.allPageViews.pageViews', 0);
        }

        return $totalPageViews > 0 ? [['label' => __('analytics.metrics.page_views'), 'value' => $totalPageViews]] : [];
    }

    private function fetchFollowerStatistics(string $orgUrn, string $timeInterval): array
    {
        $org = rawurlencode($orgUrn);
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity={$org}&timeIntervals={$timeInterval}");

        if ($response->failed()) {
            Log::warning('LinkedIn follower statistics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $elements = data_get($response->json(), 'elements', []);
        $organicFollowers = 0;
        $paidFollowers = 0;

        foreach ($elements as $element) {
            $organicFollowers += data_get($element, 'followerGains.organicFollowerGain', 0);
            $paidFollowers += data_get($element, 'followerGains.paidFollowerGain', 0);
        }

        $metrics = [];

        if ($organicFollowers > 0) {
            $metrics[] = ['label' => __('analytics.metrics.organic_followers'), 'value' => $organicFollowers];
        }

        if ($paidFollowers > 0) {
            $metrics[] = ['label' => __('analytics.metrics.paid_followers'), 'value' => $paidFollowers];
        }

        return $metrics;
    }

    private function fetchShareStatistics(string $orgUrn, string $timeInterval): array
    {
        $org = rawurlencode($orgUrn);
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity={$org}&timeIntervals={$timeInterval}");

        if ($response->failed()) {
            Log::warning('LinkedIn share statistics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $elements = data_get($response->json(), 'elements', []);
        $totalShares = 0;
        $totalClicks = 0;
        $totalLikes = 0;
        $totalComments = 0;
        $totalImpressions = 0;

        foreach ($elements as $element) {
            $stats = data_get($element, 'totalShareStatistics', []);
            $totalShares += data_get($stats, 'shareCount', 0);
            $totalClicks += data_get($stats, 'clickCount', 0);
            $totalLikes += data_get($stats, 'likeCount', 0);
            $totalComments += data_get($stats, 'commentCount', 0);
            $totalImpressions += data_get($stats, 'impressionCount', 0);
        }

        $metrics = [];

        if ($totalImpressions > 0) {
            $metrics[] = ['label' => __('analytics.metrics.impressions'), 'value' => $totalImpressions];
        }
        if ($totalClicks > 0) {
            $metrics[] = ['label' => __('analytics.metrics.clicks'), 'value' => $totalClicks];
        }
        if ($totalLikes > 0) {
            $metrics[] = ['label' => __('analytics.metrics.likes'), 'value' => $totalLikes];
        }
        if ($totalComments > 0) {
            $metrics[] = ['label' => __('analytics.metrics.comments'), 'value' => $totalComments];
        }
        if ($totalShares > 0) {
            $metrics[] = ['label' => __('analytics.metrics.shares'), 'value' => $totalShares];
        }

        return $metrics;
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->withToken($this->accessToken)
            ->withHeaders([
                'Linkedin-Version' => '202601',
                'X-Restli-Protocol-Version' => '2.0.0',
            ]);
    }
}
