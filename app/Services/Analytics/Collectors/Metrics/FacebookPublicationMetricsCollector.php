<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;

class FacebookPublicationMetricsCollector extends AbstractMetaPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $isStory = $publication->content_type === PublicationContentType::Story;
        $videoId = data_get($publication->provider_metadata, 'video_id');
        $isVideo = ! $isStory && (filled($videoId) || ! str_contains($publication->remote_id, '_'));
        $insightsId = $isVideo && filled($videoId) ? $videoId : $publication->remote_id;
        $edge = $isVideo ? 'video_insights' : 'insights';
        $fields = match (true) {
            $isStory => ['page_story_impressions_by_story_id', 'page_story_impressions_by_story_id_unique', 'story_interaction', 'pages_fb_story_thread_lightweight_reactions', 'pages_fb_story_replies', 'pages_fb_story_shares'],
            $isVideo => ['fb_reels_total_plays', 'post_video_likes_by_reaction_type', 'post_video_social_actions'],
            default => ['post_media_view', 'post_total_media_view_unique', 'post_reactions_like_total', 'post_clicks'],
        };
        $response = $this->get($account,
            rtrim((string) config('trypost.platforms.facebook.graph_api'), '/')."/{$insightsId}/{$edge}",
            ['metric' => implode(',', $fields), 'period' => 'lifetime', 'access_token' => $account->access_token],
        );
        $items = $response->json('data');

        if (! is_array($items)) {
            throw AnalyticsCollectionException::malformed('Facebook insights response lacks data.');
        }

        $values = $this->insights($items);

        if (! $isStory) {
            $details = $this->get($account,
                rtrim((string) config('trypost.platforms.facebook.graph_api'), '/')."/{$publication->remote_id}",
                [
                    'fields' => 'reactions.limit(0).summary(true),comments.limit(0).summary(true),shares',
                    'access_token' => $account->access_token,
                ],
            )->json();

            if (is_array($details)) {
                foreach ([
                    'reactions.summary.total_count' => 'reactions_count',
                    'comments.summary.total_count' => 'comments_count',
                    'shares.count' => 'shares_count',
                ] as $source => $target) {
                    $value = data_get($details, $source);

                    if (is_numeric($value)) {
                        $values[$target] = (int) $value;
                    }
                }
            }
        }

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Impressions, $values, $isStory ? 'page_story_impressions_by_story_id' : 'post_media_view'),
            $this->count(MetricKey::Reach, $values, $isStory ? 'page_story_impressions_by_story_id_unique' : 'post_total_media_view_unique'),
            $this->count(MetricKey::Views, $values, 'fb_reels_total_plays'),
            $this->count(MetricKey::Reactions, $values, 'reactions_count') ?? $this->count(MetricKey::Reactions, $values, match (true) {
                $isStory => 'pages_fb_story_thread_lightweight_reactions',
                $isVideo => 'post_video_likes_by_reaction_type',
                default => 'post_reactions_like_total',
            }),
            $this->count(MetricKey::Comments, $values, 'comments_count') ?? $this->count(MetricKey::Comments, $values, 'pages_fb_story_replies'),
            $this->count(MetricKey::Shares, $values, 'shares_count') ?? $this->count(MetricKey::Shares, $values, 'pages_fb_story_shares'),
            $this->count(MetricKey::Clicks, $values, 'post_clicks'),
            $this->count(MetricKey::TotalInteractions, $values, $isStory ? 'story_interaction' : 'post_video_social_actions'),
        ])));
    }
}
