<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AssetUsageQuery
{
    /**
     * @param  array<int, string>  $assetIds
     * @return array<string, array<string, mixed>>
     */
    public function forAssets(Workspace $workspace, array $assetIds, CarbonImmutable $nowUtc): array
    {
        $assetIds = array_values(array_unique(array_filter($assetIds)));
        $usage = [];

        foreach ($assetIds as $assetId) {
            $usage[$assetId] = $this->emptyUsage();
        }

        if ($assetIds === []) {
            return $usage;
        }

        $posts = $this->postsReferencingAssets($workspace, $assetIds)
            ->with('postPlatforms')
            ->get();

        $associated = [];

        foreach ($posts as $post) {
            $postAssetIds = collect($post->media ?? [])
                ->pluck('id')
                ->filter()
                ->unique()
                ->intersect($assetIds)
                ->values();

            foreach ($postAssetIds as $assetId) {
                $associated[$assetId] ??= [];
                $associated[$assetId][$post->id] = $post;
            }
        }

        foreach ($associated as $assetId => $postsById) {
            $usage[$assetId] = $this->usageForPosts(collect($postsById), $nowUtc);
        }

        return $usage;
    }

    /**
     * @param  array<int, string>  $assetIds
     */
    private function postsReferencingAssets(Workspace $workspace, array $assetIds): Builder
    {
        $query = Post::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('media');

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return $query->where(function ($query) use ($assetIds): void {
                foreach ($assetIds as $assetId) {
                    $query->orWhereRaw($this->postgresAssetContainsExpression(), [json_encode([['id' => $assetId]])]);
                }
            });
        }

        if ($driver === 'sqlite') {
            return $query->where(function ($query) use ($assetIds): void {
                foreach ($assetIds as $assetId) {
                    $query->orWhereRaw(
                        "exists (select 1 from json_each(posts.media) where json_extract(json_each.value, '$.id') = ?)",
                        [$assetId],
                    );
                }
            });
        }

        return $query->where(function ($query) use ($assetIds): void {
            foreach ($assetIds as $assetId) {
                $query->orWhereJsonContains('media', ['id' => $assetId]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyUsage(): array
    {
        return [
            'is_used' => false,
            'usage_count' => 0,
            'content_usage_count' => 0,
            'publication_usage_count' => 0,
            'timestamped_publication_usage_count' => 0,
            'configured_platforms' => [],
            'configured_content_types' => [],
            'published_platforms' => [],
            'published_content_types' => [],
            'content_statuses' => [],
            'publication_statuses' => [],
            'latest_content_id' => null,
            'latest_content_ids' => [],
            'latest_content_basis' => null,
            'last_used_at' => null,
            'last_use_basis' => null,
            'last_use_contexts' => [],
            'days_since_last_use' => null,
        ];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array<string, mixed>
     */
    private function usageForPosts(Collection $posts, CarbonImmutable $nowUtc): array
    {
        $enabledPlatforms = $posts
            ->flatMap(fn (Post $post) => $post->postPlatforms->filter(fn (PostPlatform $platform) => $platform->enabled));

        $publishedPlatforms = $enabledPlatforms
            ->filter(fn (PostPlatform $platform) => $platform->status === PostPlatformStatus::Published);

        $contexts = $this->timestampContexts($posts);
        $lastUsedAt = $contexts->max('used_at');
        $lastContexts = $lastUsedAt === null
            ? collect()
            : $contexts
                ->filter(fn (array $context) => $context['used_at'] === $lastUsedAt)
                ->sortBy([
                    ['content_id', 'asc'],
                    ['platform', 'asc'],
                    ['content_type', 'asc'],
                    ['use_basis', 'asc'],
                ])
                ->values();

        $latestContentIds = $lastContexts
            ->pluck('content_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $latestContentId = $latestContentIds[0] ?? $posts->sortByDesc('created_at')->first()?->id;
        $useBases = $lastContexts->pluck('use_basis')->unique()->values();

        return [
            'is_used' => $posts->isNotEmpty(),
            'usage_count' => $posts->count(),
            'content_usage_count' => $posts->count(),
            'publication_usage_count' => $publishedPlatforms->count(),
            'timestamped_publication_usage_count' => $publishedPlatforms->filter(fn (PostPlatform $platform) => $platform->published_at !== null)->count(),
            'configured_platforms' => $this->sortedEnumValues($enabledPlatforms->pluck('platform')),
            'configured_content_types' => $this->sortedEnumValues($enabledPlatforms->pluck('content_type')),
            'published_platforms' => $this->sortedEnumValues($publishedPlatforms->pluck('platform')),
            'published_content_types' => $this->sortedEnumValues($publishedPlatforms->pluck('content_type')),
            'content_statuses' => $this->sortedEnumValues($posts->pluck('status')),
            'publication_statuses' => $this->sortedEnumValues($enabledPlatforms->pluck('status')),
            'latest_content_id' => $latestContentId,
            'latest_content_ids' => $latestContentIds,
            'latest_content_basis' => $lastUsedAt === null && $latestContentId !== null ? 'content_created_at_fallback' : null,
            'last_used_at' => $lastUsedAt,
            'last_use_basis' => $useBases->count() > 1 ? 'mixed' : $useBases->first(),
            'last_use_contexts' => $lastContexts->all(),
            'days_since_last_use' => $lastUsedAt === null ? null : $this->daysSince($lastUsedAt, $nowUtc),
        ];
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return Collection<int, array<string, mixed>>
     */
    private function timestampContexts(Collection $posts): Collection
    {
        return $posts->flatMap(function (Post $post): array {
            $published = $post->postPlatforms
                ->filter(fn (PostPlatform $platform) => $platform->enabled && $platform->status === PostPlatformStatus::Published);

            $platformContexts = $published
                ->filter(fn (PostPlatform $platform) => $platform->published_at !== null)
                ->map(fn (PostPlatform $platform) => $this->context($post, $platform, $platform->published_at, 'platform_published_at'))
                ->all();

            if ($platformContexts !== []) {
                return $platformContexts;
            }

            if ($post->published_at !== null && in_array($post->status, [PostStatus::Published, PostStatus::PartiallyPublished], true)) {
                $rows = $published->isNotEmpty() ? $published : collect([null]);

                return $rows
                    ->map(fn (?PostPlatform $platform) => $this->context($post, $platform, $post->published_at, 'post_published_at'))
                    ->all();
            }

            if ($post->scheduled_at !== null && $post->status === PostStatus::Scheduled) {
                $rows = $post->postPlatforms
                    ->filter(fn (PostPlatform $platform) => $platform->enabled);

                if ($rows->isEmpty()) {
                    $rows = collect([null]);
                }

                return $rows
                    ->map(fn (?PostPlatform $platform) => $this->context($post, $platform, $post->scheduled_at, 'scheduled_at'))
                    ->all();
            }

            return [];
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Post $post, ?PostPlatform $platform, mixed $usedAt, string $basis): array
    {
        return [
            'content_id' => $post->id,
            'platform' => $platform?->platform?->value,
            'content_type' => $platform?->content_type?->value,
            'content_status' => $post->status->value,
            'publication_status' => $platform?->status?->value,
            'used_at' => CarbonImmutable::parse($usedAt)->utc()->toAtomString(),
            'use_basis' => $basis,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return array<int, string>
     */
    private function sortedEnumValues(Collection $values): array
    {
        return $values
            ->map(fn (mixed $value) => $value?->value ?? $value)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function daysSince(string $lastUsedAt, CarbonImmutable $nowUtc): int
    {
        $lastDate = CarbonImmutable::parse($lastUsedAt)->utc()->startOfDay();
        $nowDate = $nowUtc->utc()->startOfDay();

        return max(0, (int) $lastDate->diffInDays($nowDate, false));
    }

    private function postgresAssetContainsExpression(): string
    {
        return 'media::jsonb @> ?::jsonb';
    }
}
