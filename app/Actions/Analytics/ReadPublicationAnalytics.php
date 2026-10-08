<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\MetricValue;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Analytics\EngagementRate;
use Illuminate\Support\Collection;

class ReadPublicationAnalytics
{
    /** @return array<string, mixed> */
    public function latestForWorkspacePublication(Workspace $workspace, string $publicationId): array
    {
        $publication = AnalyticsPublication::query()
            ->with('socialAccount')
            ->available()
            ->whereBelongsTo($workspace)
            ->whereIn('platform', Platform::analyticsValues())
            ->findOrFail($publicationId);

        return $this->latestForPublication($publication);
    }

    /** @return array<string, mixed> */
    public function forPost(Post $post): array
    {
        return [
            'post_id' => $post->id,
            'platform' => $post->platform?->value,
            'publish_status' => $post->publish_status->value,
            'platform_post_id' => $post->platform_post_id,
            'platform_url' => $post->platform_url,
            'metrics' => $this->metricsFor($post),
        ];
    }

    /** @return array<string, mixed> */
    public function metricsFor(Post $post): array
    {
        return $this->visibleDetail($this->latestFor($post));
    }

    /**
     * The latest metrics of each post, keyed by post id.
     *
     * @param  Collection<int, Post>  $posts
     * @return array<string, array<string, mixed>>
     */
    public function latestForPosts(string $workspaceId, Collection $posts): array
    {
        $eligible = $posts->filter(fn (Post $post): bool => $this->isCollectable($post));

        $publications = $eligible->isEmpty() ? collect() : AnalyticsPublication::query()
            ->available()
            ->where('workspace_id', $workspaceId)
            ->whereIn('post_id', $eligible->pluck('id'))
            ->whereIn('platform', Platform::analyticsValues())
            ->get()
            ->keyBy('post_id');

        $snapshots = collect();

        if ($publications->isNotEmpty()) {
            $snapshotTable = (new AnalyticsPublicationDailySnapshot)->getTable();
            $latestDates = AnalyticsPublicationDailySnapshot::query()
                ->whereIn('publication_id', $publications->pluck('id'))
                ->select('publication_id')
                ->selectRaw('MAX(date) as latest_date')
                ->groupBy('publication_id');

            $snapshots = AnalyticsPublicationDailySnapshot::query()
                ->joinSub($latestDates, 'latest', fn ($join) => $join
                    ->on("{$snapshotTable}.publication_id", '=', 'latest.publication_id')
                    ->on("{$snapshotTable}.date", '=', 'latest.latest_date'))
                ->select("{$snapshotTable}.*")
                ->get()
                ->keyBy('publication_id');
        }

        return $posts->mapWithKeys(function (Post $post) use ($publications, $snapshots): array {
            if ($post->publish_status !== PublishStatus::Published || ! $post->platform_post_id) {
                return [$post->id => $this->unavailable('not_published')];
            }

            if (! in_array($post->platform?->value, Platform::analyticsValues(), true)) {
                return [$post->id => $this->unavailable('platform_not_supported')];
            }

            $publication = $publications->get($post->id)?->setRelation(
                'socialAccount',
                $post->relationLoaded('socialAccount') ? $post->socialAccount : null,
            );

            return [$post->id => $publication
                ? $this->detail($publication, $snapshots->get($publication->id))
                : $this->unavailable('not_collected')];
        })->all();
    }

    /** @return array<string, mixed> */
    public function latestFor(Post $post): array
    {
        if ($post->publish_status !== PublishStatus::Published || ! $post->platform_post_id) {
            return $this->unavailable('not_published');
        }

        if (! in_array($post->platform?->value, Platform::analyticsValues(), true)) {
            return $this->unavailable('platform_not_supported');
        }

        $publication = AnalyticsPublication::query()
            ->with('socialAccount')
            ->available()
            ->where('workspace_id', $post->workspace_id)
            ->where('post_id', $post->id)
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

        $snapshot = $publication->dailySnapshots()->orderByDesc('date')->first();

        return $this->detail($publication, $snapshot);
    }

    private function isCollectable(Post $post): bool
    {
        return $post->publish_status === PublishStatus::Published
            && filled($post->platform_post_id)
            && in_array($post->platform?->value, Platform::analyticsValues(), true);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function visibleDetail(array $detail): array
    {
        if (! data_get($detail, 'available')) {
            return ['unsupported' => true, 'reason' => data_get($detail, 'reason')];
        }

        return $detail;
    }

    /** @return array<string, mixed> */
    private function detail(AnalyticsPublication $publication, ?AnalyticsPublicationDailySnapshot $snapshot): array
    {
        $publication->loadMissing('socialAccount');

        return [
            'available' => true,
            'reason' => null,
            'publication' => [
                'id' => $publication->id,
                'post_id' => $publication->post_id,
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
                'account_avatar_url' => $publication->socialAccount?->avatar_url ?? $publication->account_avatar_url,
            ],
            'snapshot' => $snapshot ? $this->snapshot($snapshot) : null,
            'metrics' => $snapshot ? $this->withEngagementRate($snapshot) : [],
        ];
    }

    /** @return array<string, mixed> */
    private function withEngagementRate(AnalyticsPublicationDailySnapshot $snapshot): array
    {
        $metrics = $snapshot->metrics ?? [];
        $rate = EngagementRate::of($snapshot->engagement_count, $snapshot->exposure_count);

        if ($rate !== null) {
            $metrics[MetricKey::EngagementRate->value] = (new MetricValue(
                key: MetricKey::EngagementRate,
                value: $rate,
                unit: MetricUnit::Percent,
                timeBasis: MetricTimeBasis::Lifetime,
                precision: MetricPrecision::Exact,
                availability: MetricAvailability::Available,
                providerMetric: "derived_engagements_per_{$snapshot->exposure_kind?->value}",
            ))->toArray();
        }

        return $metrics;
    }

    /** @return array<string, mixed> */
    private function snapshot(AnalyticsPublicationDailySnapshot $snapshot): array
    {
        return [
            'date' => $snapshot->date->toDateString(),
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
