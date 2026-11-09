<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Each read is optional on its own: Page insights are refused for small Pages,
 * and `comments` is user content behind `pages_read_user_content`, which the
 * connect flow does not request. Whatever the Page token can read is kept.
 */
class FacebookPublicationMetricsCollector extends AbstractMetaPublicationMetricsCollector
{
    protected function postsEdge(): string
    {
        return 'published_posts';
    }

    /**
     * A post read by its bare video id makes no other read of the post, so a
     * permission refusal there may hide a deleted video: the confirmation reads it.
     * Only once its reads were refused, never when the Page did not grant reading
     * it at all, since nothing was read then.
     */
    protected function mayBeDeleted(AnalyticsCollectionException $exception, AnalyticsPublication $publication): bool
    {
        return parent::mayBeDeleted($exception, $publication)
            || ($exception->category === 'permission'
                && filled($this->refusals)
                && $this->isReadByVideoId($publication));
    }

    /**
     * Graph answers a deleted Page post's own node with #10, which also means a missing
     * permission, and only its insights edge with "object does not exist".
     */
    protected function readPost(SocialAccount $account, AnalyticsPublication $publication, string $graph): Response
    {
        if ($this->isReadByVideoId($publication)) {
            return parent::readPost($account, $publication, $graph);
        }

        return $this->get($account, "{$graph}/{$publication->remote_id}/insights", ['metric' => 'post_media_view', 'period' => 'lifetime']);
    }

    /**
     * A Page post id is `{page id}_{post id}`; a video or reel is known by its bare id.
     */
    private function isReadByVideoId(AnalyticsPublication $publication): bool
    {
        return ! Str::contains($publication->remote_id, '_');
    }

    protected function read(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, 'pages_read_engagement');
        $graph = $account->platform->graphApiUrl();
        $isStory = $publication->content_type === PublicationContentType::Story;
        $videoId = data_get($publication->provider_metadata, 'video_id');
        $isVideo = ! $isStory && (filled($videoId) || $this->isReadByVideoId($publication));
        $insightsId = $isVideo && filled($videoId) ? $videoId : $publication->remote_id;
        $edge = $isVideo ? 'video_insights' : 'insights';
        $fields = match (true) {
            $isStory => ['page_story_impressions_by_story_id', 'page_story_impressions_by_story_id_unique', 'story_interaction', 'pages_fb_story_thread_lightweight_reactions', 'pages_fb_story_replies', 'pages_fb_story_shares'],
            $isVideo => ['fb_reels_total_plays', 'post_video_likes_by_reaction_type', 'post_video_social_actions'],
            default => ['post_media_view', 'post_total_media_view_unique', 'post_reactions_like_total', 'post_clicks'],
        };
        $values = [];

        if ($account->missingScope('read_insights') === null) {
            $values = $this->insights($this->optionalInsightItems($account, "{$graph}/{$insightsId}/{$edge}", $fields, [
                'period' => 'lifetime',
            ]));
        } else {
            $this->refusals[] = 'permission';
        }

        if (! $isStory && ! $this->isReadByVideoId($publication)) {
            $post = "{$graph}/{$publication->remote_id}";
            $details = array_merge(
                $this->optional($account, $post, ['fields' => 'reactions.limit(0).summary(total_count),shares']),
                $this->optional($account, $post, ['fields' => 'comments.limit(0).summary(total_count)']),
            );

            foreach ([
                'reactions.summary.total_count' => 'reactions_count',
                'comments.summary.total_count' => 'comments_count',
            ] as $source => $target) {
                $value = data_get($details, $source);

                if (is_numeric($value)) {
                    $values[$target] = (int) $value;
                }
            }

            if (array_key_exists('reactions', $details)) {
                $values['shares_count'] = (int) data_get($details, 'shares.count', 0);
            }
        }

        $metrics = $this->withEngagements($this->present([
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
        ]));

        if ($metrics === [] && in_array('permission', $this->refusals, true)) {
            throw new AnalyticsCollectionException('permission', 'Facebook refused every publication metrics read.');
        }

        return $this->observation($date, $metrics);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function optional(SocialAccount $account, string $url, array $query): array
    {
        try {
            return (array) $this->get($account, $url, $query)->json();
        } catch (AnalyticsCollectionException $exception) {
            if (! in_array($exception->category, ['permission', 'malformed'], true)) {
                throw $exception;
            }

            $this->recordRefusal($exception);
            Log::info('analytics.facebook_read_refused', [
                'path' => parse_url($url, PHP_URL_PATH),
                'category' => $exception->category,
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
