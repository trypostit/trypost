<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Enums\SocialAccount\Platform;
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

    /** @return Collection<int, array<string, mixed>> */
    public function forPostLegacy(Post $post): Collection
    {
        return $this->forPost($post)->map(function (array $row): array {
            $detail = $row['metrics'];
            $row['metrics'] = $this->legacyMetrics($detail, $row['platform']);

            if ($detail['available'] ?? false) {
                $row['analytics'] = $detail;
            }

            return $row;
        });
    }

    /** @return array<string, mixed>|list<array{label: string, value: int|float}> */
    public function forPlatformLegacy(PostPlatform $postPlatform): array
    {
        return $this->legacyMetrics($this->forPlatform($postPlatform), $postPlatform->platform->value);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|list<array{label: string, value: int|float}>
     */
    private function legacyMetrics(array $detail, string $platform): array
    {
        if (! ($detail['available'] ?? false)) {
            return $detail;
        }

        $metrics = [];

        foreach ($detail['metrics'] as $key => $fact) {
            if (data_get($fact, 'availability') !== 'available'
                || data_get($fact, 'unit') !== 'count'
                || ! is_numeric(data_get($fact, 'value'))) {
                continue;
            }

            $label = match ($key) {
                'reactions' => match ($platform) {
                    Platform::Instagram->value, Platform::InstagramFacebook->value,
                    Platform::Threads->value, Platform::X->value, Platform::TikTok->value, Platform::YouTube->value,
                    Platform::Bluesky->value => 'likes',
                    Platform::Mastodon->value => 'favourites',
                    default => 'reactions',
                },
                'shares' => match ($platform) {
                    Platform::X->value => 'retweets',
                    Platform::Mastodon->value => 'reblogs',
                    default => 'shares',
                },
                'total_interactions' => 'interactions',
                'engagements' => 'engagement',
                default => $key,
            };
            $translationKey = "analytics.metrics.{$label}";

            if (! trans()->has($translationKey)) {
                continue;
            }

            $metrics[] = [
                'label' => __($translationKey),
                'value' => $fact['value'],
            ];
        }

        return $metrics;
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
