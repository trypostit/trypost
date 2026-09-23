<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\PostPlatform;

class PublicationAnalyticsQuery
{
    /** @return array<string, mixed> */
    public function latestForPostPlatform(PostPlatform $postPlatform): array
    {
        if (! in_array($postPlatform->platform->value, Platform::analyticsValues(), true)) {
            return $this->unavailable('platform_not_supported');
        }

        if ($postPlatform->status !== Status::Published || ! $postPlatform->platform_post_id) {
            return $this->unavailable('not_published');
        }

        $publication = AnalyticsPublication::query()
            ->where('workspace_id', $postPlatform->post->workspace_id)
            ->where('post_platform_id', $postPlatform->id)
            ->whereIn('platform', Platform::analyticsValues())
            ->first();

        return $publication ? $this->latestForPublication($publication) : $this->unavailable('not_collected');
    }

    /** @return array<string, mixed> */
    public function latestForPublication(AnalyticsPublication $publication): array
    {
        if (! in_array($publication->platform->value, Platform::analyticsValues(), true)) {
            return $this->unavailable('platform_not_supported');
        }

        $snapshot = $publication->dailySnapshots()->orderByDesc('snapshot_date')->first();

        return [
            'available' => true,
            'reason' => null,
            'publication' => [
                'id' => $publication->id,
                'post_platform_id' => $publication->post_platform_id,
                'social_account_key' => $publication->social_account_key,
                'platform' => $publication->platform->value,
                'origin' => $publication->origin->value,
                'content_type' => $publication->content_type->value,
                'availability' => $publication->availability->value,
                'provider_published_at' => $publication->provider_published_at?->toIso8601String(),
                'permalink' => $publication->permalink,
                'excerpt' => $publication->excerpt,
                'preview_metadata' => $publication->preview_metadata,
                'account_display_name' => $publication->account_display_name,
                'account_username' => $publication->account_username,
                'account_avatar_url' => $publication->account_avatar_url,
            ],
            'snapshot' => $snapshot ? $this->snapshot($snapshot) : null,
            'metrics' => $snapshot?->metrics ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(AnalyticsPublicationDailySnapshot $snapshot): array
    {
        return [
            'date' => $snapshot->snapshot_date->toDateString(),
            'collected_at' => $snapshot->collected_at?->toIso8601String(),
            'provider_observed_at' => $snapshot->provider_observed_at?->toIso8601String(),
            'reactions_count' => $snapshot->reactions_count,
            'comments_count' => $snapshot->comments_count,
            'shares_count' => $snapshot->shares_count,
            'saves_count' => $snapshot->saves_count,
            'views_count' => $snapshot->views_count,
            'impressions_count' => $snapshot->impressions_count,
            'reach_count' => $snapshot->reach_count,
            'engagement_count' => $snapshot->engagement_count,
            'exposure_count' => $snapshot->exposure_count,
            'exposure_kind' => $snapshot->exposure_kind?->value,
            'watch_time_milliseconds' => $snapshot->watch_time_milliseconds,
            'average_watch_time_milliseconds' => $snapshot->average_watch_time_milliseconds,
        ];
    }

    /** @return array{available: false, reason: string, publication: null, snapshot: null, metrics: array} */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'publication' => null,
            'snapshot' => null,
            'metrics' => [],
        ];
    }
}
