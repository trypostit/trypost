<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Contracts\Analytics\PublicationMetricsCollector;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class InstagramPublicationMetricsCollector extends AbstractMetaPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $isStory = $publication->content_type === PublicationContentType::Story;
        $isReel = $publication->content_type === PublicationContentType::Reel;
        $fields = $isStory
            ? ['reach', 'views', 'replies']
            : ['reach', 'views', 'likes', 'comments', 'shares', 'saved'];
        $url = $account->platform->instagramGraphBaseUrl()."/{$publication->provider_post_id}/insights";
        $response = $this->get($account, $url, ['metric' => implode(',', $fields), 'access_token' => $account->access_token]);
        $items = $response->json('data');

        if (! is_array($items)) {
            throw AnalyticsCollectionException::malformed('Instagram insights response lacks data.');
        }

        $values = $this->insights($items);
        $extras = $isStory
            ? $this->optionalInsights($account, $url, ['navigation', 'taps_forward', 'taps_back', 'exits'])
            : $this->optionalInsights($account, $url, ['total_interactions', 'reposts', 'follows', 'profile_visits', 'profile_activity']);
        $values = array_merge($values, $extras);
        $metrics = $this->present([
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
        ]);

        if ($isReel) {
            $reelValues = $this->optionalInsights($account, $url, ['ig_reels_video_view_total_time', 'ig_reels_avg_watch_time']);
            $metrics = array_merge($metrics, $this->present([
                $this->decimal(MetricKey::WatchTimeMilliseconds, $reelValues, 'ig_reels_video_view_total_time', MetricUnit::Milliseconds),
                $this->decimal(MetricKey::AverageWatchTimeMilliseconds, $reelValues, 'ig_reels_avg_watch_time', MetricUnit::Milliseconds),
            ]));
        }

        return $this->observation($date, $this->withEngagements($metrics));
    }

    /** @param list<string> $fields @return array<string, int|float> */
    private function optionalInsights(SocialAccount $account, string $url, array $fields): array
    {
        try {
            $response = $this->get($account, $url, [
                'metric' => implode(',', $fields),
                'access_token' => $account->access_token,
            ]);
        } catch (AnalyticsCollectionException) {
            return [];
        }

        $data = $response->json('data');

        return is_array($data) ? $this->insights($data) : [];
    }
}
