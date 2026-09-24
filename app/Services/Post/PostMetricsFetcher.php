<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Models\Post;
use App\Models\PostPlatform;
use App\Queries\Analytics\PublicationAnalyticsQuery;
use Illuminate\Support\Collection;

/**
 * Local-only facade shared by web, REST, and MCP post detail reads.
 */
class PostMetricsFetcher
{
    public function __construct(private readonly PublicationAnalyticsQuery $publications) {}

    /** @return Collection<int, array<string, mixed>> */
    public function forPost(Post $post): Collection
    {
        $destinations = $post->postPlatforms
            ->where('enabled', true)
            ->values();
        $details = $this->publications->latestForPost($post, $destinations);

        return $destinations->map(fn (PostPlatform $destination): array => [
            'post_platform_id' => $destination->id,
            'platform' => $destination->platform->value,
            'status' => $destination->status->value,
            'platform_post_id' => $destination->platform_post_id,
            'platform_url' => $destination->platform_url,
            'metrics' => $this->visibleDetail($details[$destination->id]),
        ]);
    }

    /** @return array<string, mixed> */
    public function forPlatform(PostPlatform $postPlatform): array
    {
        $detail = $this->publications->latestForPostPlatform($postPlatform);

        return $this->visibleDetail($detail);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function visibleDetail(array $detail): array
    {
        if (! $detail['available']) {
            return ['unsupported' => true, 'reason' => $detail['reason']];
        }

        return $detail;
    }
}
