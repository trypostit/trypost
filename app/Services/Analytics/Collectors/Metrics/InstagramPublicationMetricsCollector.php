<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class InstagramPublicationMetricsCollector extends AbstractMetaPublicationMetricsCollector
{
    private const array STORY_NAVIGATION_ACTIONS = [
        'TAP_FORWARD' => 'taps_forward',
        'TAP_BACK' => 'taps_back',
        'TAP_EXIT' => 'exits',
        'SWIPE_FORWARD' => 'swipes_forward',
    ];

    protected function postsEdge(): string
    {
        return 'media';
    }

    protected function read(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, ...$account->platform === Platform::InstagramFacebook
            ? ['instagram_basic', 'instagram_manage_insights', 'pages_read_engagement']
            : ['instagram_business_basic', 'instagram_business_manage_insights']);
        $isStory = $publication->content_type === PublicationContentType::Story;
        $isReel = $publication->content_type === PublicationContentType::Reel;
        $fields = $isStory
            ? ['reach', 'views', 'replies']
            : ['reach', 'views', 'likes', 'comments', 'shares', 'saved'];
        $url = "{$account->platform->graphApiUrl()}/{$publication->remote_id}/insights";
        $response = $this->get($account, $url, ['metric' => implode(',', $fields)]);
        $items = $response->json('data');

        if (! is_array($items)) {
            throw AnalyticsCollectionException::malformed('Instagram insights response lacks data.');
        }

        $optional = match (true) {
            $isStory => ['shares', 'total_interactions', 'reposts', 'follows', 'profile_visits', 'profile_activity'],
            $isReel => ['total_interactions', 'reposts', 'ig_reels_video_view_total_time', 'ig_reels_avg_watch_time'],
            default => ['total_interactions', 'reposts', 'follows', 'profile_visits', 'profile_activity'],
        };
        $values = array_merge(
            $this->insights($items),
            $this->insights($this->optionalInsightItems($account, $url, $optional)),
            $isStory ? $this->storyNavigation($account, $url) : [],
        );

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Reach, $values, 'reach'),
            $this->count(MetricKey::Views, $values, 'views'),
            $this->count(MetricKey::Reactions, $values, 'likes'),
            $this->count(MetricKey::Comments, $values, $isStory ? 'replies' : 'comments'),
            $this->count(MetricKey::Shares, $values, 'shares'),
            $this->count(MetricKey::Saves, $values, 'saved'),
            $this->count(MetricKey::Reposts, $values, 'reposts'),
            $this->count(MetricKey::TotalInteractions, $values, 'total_interactions'),
            $this->count(MetricKey::Follows, $values, 'follows'),
            $this->count(MetricKey::ProfileVisits, $values, 'profile_visits'),
            $this->count(MetricKey::ProfileActivity, $values, 'profile_activity'),
            $this->count(MetricKey::StoryNavigation, $values, 'navigation'),
            $this->count(MetricKey::StoryTapsForward, $values, 'taps_forward'),
            $this->count(MetricKey::StoryTapsBack, $values, 'taps_back'),
            $this->count(MetricKey::StoryExits, $values, 'exits'),
            $this->count(MetricKey::StorySwipesForward, $values, 'swipes_forward'),
            $this->decimal(MetricKey::WatchTimeMilliseconds, $values, 'ig_reels_video_view_total_time', MetricUnit::Milliseconds),
            $this->decimal(MetricKey::AverageWatchTimeMilliseconds, $values, 'ig_reels_avg_watch_time', MetricUnit::Milliseconds),
        ])));
    }

    /** @return array<string, int|float> */
    private function storyNavigation(SocialAccount $account, string $url): array
    {
        $item = data_get($this->optionalInsightItems($account, $url, ['navigation'], [
            'breakdown' => 'story_navigation_action_type',
        ]), 0);
        $values = [];
        $total = 0;

        foreach ((array) data_get($item, 'total_value.breakdowns.0.results', []) as $result) {
            $value = data_get($result, 'value');

            if (! is_numeric($value)) {
                continue;
            }

            $total += $value + 0;
            $key = data_get(self::STORY_NAVIGATION_ACTIONS, (string) data_get($result, 'dimension_values.0'));

            if ($key !== null) {
                $values[$key] = $value + 0;
            }
        }

        $navigation = data_get($item, 'total_value.value');

        if (is_numeric($navigation)) {
            $values['navigation'] = $navigation + 0;
        } elseif (data_get($item, 'total_value.breakdowns.0.results') !== null) {
            $values['navigation'] = $total;
        }

        return $values;
    }
}
