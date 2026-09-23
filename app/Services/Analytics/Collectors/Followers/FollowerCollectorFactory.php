<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Contracts\Analytics\FollowerCollector;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;

class FollowerCollectorFactory
{
    public function supports(Platform $platform): bool
    {
        return in_array($platform, [
            Platform::Instagram,
            Platform::InstagramFacebook,
            Platform::Facebook,
            Platform::Threads,
            Platform::X,
            Platform::Pinterest,
            Platform::YouTube,
            Platform::TikTok,
            Platform::Bluesky,
            Platform::Mastodon,
        ], true);
    }

    public function for(Platform $platform): FollowerCollector
    {
        return match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramFollowerCollector::class),
            Platform::Facebook => app(FacebookFollowerCollector::class),
            Platform::Threads => app(ThreadsFollowerCollector::class),
            Platform::X => app(XFollowerCollector::class),
            Platform::Pinterest => app(PinterestFollowerCollector::class),
            Platform::YouTube => app(YouTubeFollowerCollector::class),
            Platform::TikTok => app(TikTokFollowerCollector::class),
            Platform::Bluesky => app(BlueskyFollowerCollector::class),
            Platform::Mastodon => app(MastodonFollowerCollector::class),
            default => throw AnalyticsCollectionException::unsupported("{$platform->value} follower analytics is excluded"),
        };
    }
}
