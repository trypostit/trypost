<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Analytics\PublishingCounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildNetworkHealth
{
    /**
     * How each enabled network is doing: its channels by connection status,
     * and the posts TryPost sent there in the last 24 hours and 7 days. The
     * network failing most in the last 24 hours comes first. Everything is
     * counted in SQL.
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
            ->tallyBy(['platform', 'status'])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $group): array => ["{$group->platform}|{$group->status}" => (int) $group->total]);

        $lastDay = self::sentSince($now->subDay(), $now);
        $lastWeek = self::sentSince($now->subDays(7), $now);

        $lastFailures = Post::query()
            ->createdInTryPost()
            ->whereIn('posts.publish_status', PublishingCounts::failedStatuses())
            ->where('posts.publication_updated_at', '>=', $now->subDays(7))
            ->tallyBy(['posts.platform'])
            ->withLatest('posts.publication_updated_at', 'last_failure_at')
            ->toBase()
            ->pluck('last_failure_at', 'platform');

        return collect(Platform::enabledCases())
            ->map(fn (Platform $platform): array => [
                'platform' => $platform->value,
                'label' => $platform->label(),
                'channels' => [
                    'connected' => self::channelCount($channels, $platform, Status::Connected),
                    'token_expired' => self::channelCount($channels, $platform, Status::TokenExpired),
                    'disconnected' => self::channelCount($channels, $platform, Status::Disconnected),
                ],
                'last_24h' => PublishingCounts::of($lastDay->get($platform->value, collect())),
                'last_7d' => PublishingCounts::of($lastWeek->get($platform->value, collect())),
                'last_failure_at' => self::instant($lastFailures->get($platform->value)),
            ])
            ->sortByDesc(fn (array $network): array => [data_get($network, 'last_24h.failure_rate'), data_get($network, 'last_24h.failed')])
            ->values()
            ->all();
    }

    /**
     * The posts TryPost sent since then, tallied by publication status, per
     * platform: those that went out by when they were published, the refused
     * ones by when they failed ({@see PublishingCounts::countedBetween()}).
     *
     * @return Collection<string, Collection<int, array{publish_status: string, total: int}>>
     */
    private static function sentSince(CarbonImmutable $since, CarbonImmutable $now): Collection
    {
        return PublishingCounts::countedBetween(Post::query()->createdInTryPost(), $since, $now, PublishingCounts::countedStatuses())
            ->tallyBy(['posts.platform', 'posts.publish_status'])
            ->toBase()
            ->get()
            ->map(fn (object $group): array => [
                'platform' => (string) $group->platform,
                'publish_status' => (string) $group->publish_status,
                'total' => (int) $group->total,
            ])
            ->groupBy('platform');
    }

    /**
     * @param  Collection<string, int>  $channels
     */
    private static function channelCount(Collection $channels, Platform $platform, Status $status): int
    {
        return (int) $channels->get("{$platform->value}|{$status->value}", 0);
    }

    private static function instant(?string $timestamp): ?string
    {
        if (blank($timestamp)) {
            return null;
        }

        return CarbonImmutable::parse($timestamp, 'UTC')->toIso8601String();
    }
}
