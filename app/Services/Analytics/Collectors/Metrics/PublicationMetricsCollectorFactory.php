<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Contracts\Analytics\PublicationMetricsCollector;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;

class PublicationMetricsCollectorFactory
{
    public function for(Platform $platform): PublicationMetricsCollector
    {
        return match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramPublicationMetricsCollector::class),
            Platform::Facebook => app(FacebookPublicationMetricsCollector::class),
            Platform::Threads => app(ThreadsPublicationMetricsCollector::class),
            Platform::X => app(XPublicationMetricsCollector::class),
            Platform::Pinterest => app(PinterestPublicationMetricsCollector::class),
            Platform::YouTube => app(YouTubePublicationMetricsCollector::class),
            Platform::TikTok => app(TikTokPublicationMetricsCollector::class),
            Platform::Bluesky => app(BlueskyPublicationMetricsCollector::class),
            Platform::Mastodon => app(MastodonPublicationMetricsCollector::class),
            default => throw AnalyticsCollectionException::unsupported("{$platform->value} publication metrics are excluded"),
        };
    }
}
