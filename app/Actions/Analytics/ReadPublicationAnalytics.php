<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReadPublicationAnalytics
{
    /** @return array<string, mixed> */
    public function latestForPostPublication(Post $post, ?string $publicationId = null): array
    {
        $publication = AnalyticsPublication::query()
            ->available()
            ->where('workspace_id', $post->workspace_id)
            ->whereIn('platform', Platform::analyticsValues())
            ->whereHas('postPlatform', fn (Builder $query): Builder => $query->whereBelongsTo($post))
            ->when($publicationId, fn (Builder $query): Builder => $query->whereKey($publicationId))
            ->orderByDesc('provider_published_at')
            ->firstOrFail();

        return $this->latestForPublication($publication);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function forPost(Post $post): Collection
    {
        $destinations = $post->postPlatforms
            ->where('enabled', true)
            ->values();
        $details = $this->latestForPost($post, $destinations);

        return $destinations->map(fn (PostPlatform $destination): array => [
            'post_platform_id' => $destination->id,
            'platform' => $destination->platform->value,
            'status' => $destination->status->value,
            'platform_post_id' => $destination->platform_post_id,
            'platform_url' => $destination->platform_url,
            'metrics' => $this->visibleDetail(data_get($details, $destination->id, $this->unavailable('not_collected'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function forPlatform(PostPlatform $postPlatform): array
    {
        return $this->visibleDetail($this->latestForPostPlatform($postPlatform));
    }

    /**
     * @param  Collection<int, PostPlatform>  $destinations
     * @return array<string, array<string, mixed>>
     */
    public function latestForPost(Post $post, Collection $destinations): array
    {
        $eligible = $destinations->filter(fn (PostPlatform $destination): bool => $this->isCollectable($destination));

        $publications = $eligible->isEmpty() ? collect() : AnalyticsPublication::query()
            ->available()
            ->where('workspace_id', $post->workspace_id)
            ->whereIn('post_platform_id', $eligible->pluck('id'))
            ->whereIn('platform', Platform::analyticsValues())
            ->get()
            ->keyBy('post_platform_id');

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

        return $destinations->mapWithKeys(function (PostPlatform $destination) use ($publications, $snapshots): array {
            if ($destination->status !== Status::Published || ! $destination->platform_post_id) {
                return [$destination->id => $this->unavailable('not_published')];
            }

            if (! in_array($destination->platform->value, Platform::analyticsValues(), true)) {
                return [$destination->id => $this->unavailable('platform_not_supported')];
            }

            $publication = $publications->get($destination->id);

            return [$destination->id => $publication
                ? $this->detail($publication, $snapshots->get($publication->id))
                : $this->unavailable('not_collected')];
        })->all();
    }

    /** @return array<string, mixed> */
    public function latestForPostPlatform(PostPlatform $postPlatform): array
    {
        if ($postPlatform->status !== Status::Published || ! $postPlatform->platform_post_id) {
            return $this->unavailable('not_published');
        }

        if (! in_array($postPlatform->platform->value, Platform::analyticsValues(), true)) {
            return $this->unavailable('platform_not_supported');
        }

        $publication = AnalyticsPublication::query()
            ->available()
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

        $snapshot = $publication->dailySnapshots()->orderByDesc('date')->first();

        return $this->detail($publication, $snapshot);
    }

    private function isCollectable(PostPlatform $destination): bool
    {
        return $destination->status === Status::Published
            && filled($destination->platform_post_id)
            && in_array($destination->platform->value, Platform::analyticsValues(), true);
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
