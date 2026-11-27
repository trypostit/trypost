<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Admin\PublishingCounts;
use Carbon\CarbonImmutable;

class BuildNetworkHealth
{
    /**
     * How each enabled network is doing: its channels by connection status,
     * and the posts TryPost sent there in the last 24 hours and 7 days. The
     * network failing most in the last 24 hours comes first.
     *
     * @return list<array{
     *     platform: string,
     *     label: string,
     *     channels: array{connected: int, token_expired: int, disconnected: int},
     *     last_24h: array{published: int, failed: int, failure_rate: float},
     *     last_7d: array{published: int, failed: int, failure_rate: float},
     *     last_failure_at: string|null
     * }>
     */
    public static function execute(): array
    {
        $now = CarbonImmutable::now('UTC');

        $channels = SocialAccount::query()
            ->toBase()
            ->get(['platform', 'status'])
            ->countBy(fn (object $channel): string => "{$channel->platform}|{$channel->status}");

        $posts = Post::query()
            ->createdInTryPost()
            ->whereIn('posts.publish_status', PublishingCounts::countedStatuses())
            ->where('posts.publication_updated_at', '>=', $now->subDays(7))
            ->toBase()
            ->get(['posts.platform', 'posts.publish_status', 'posts.publication_updated_at'])
            ->map(fn (object $post): array => [
                'platform' => (string) $post->platform,
                'failed' => PublishingCounts::isFailed((string) $post->publish_status),
                'at' => CarbonImmutable::parse($post->publication_updated_at, 'UTC'),
            ])
            ->groupBy('platform');

        return collect(Platform::cases())
            ->filter(fn (Platform $platform): bool => $platform->isEnabled())
            ->map(function (Platform $platform) use ($channels, $posts, $now): array {
                $networkPosts = $posts->get($platform->value, collect());
                $lastFailure = $networkPosts->where('failed', true)->max('at');

                return [
                    'platform' => $platform->value,
                    'label' => $platform->label(),
                    'channels' => [
                        'connected' => $channels->get("{$platform->value}|".Status::Connected->value, 0),
                        'token_expired' => $channels->get("{$platform->value}|".Status::TokenExpired->value, 0),
                        'disconnected' => $channels->get("{$platform->value}|".Status::Disconnected->value, 0),
                    ],
                    'last_24h' => PublishingCounts::of($networkPosts->filter(fn (array $post): bool => $post['at']->gte($now->subDay()))),
                    'last_7d' => PublishingCounts::of($networkPosts),
                    'last_failure_at' => $lastFailure?->toIso8601String(),
                ];
            })
            ->sortByDesc(fn (array $network): array => [$network['last_24h']['failure_rate'], $network['last_24h']['failed']])
            ->values()
            ->all();
    }
}
