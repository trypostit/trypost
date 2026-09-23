<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Contracts\Analytics\PublicationHistoryCollector;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;

class PublicationHistoryCollectorFactory
{
    public function for(SocialAccount $account): PublicationHistoryCollector
    {
        return match ($account->platform) {
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramPublicationCollector::class),
            Platform::Facebook => app(FacebookPublicationCollector::class),
            Platform::Threads => app(ThreadsPublicationCollector::class),
            Platform::X => app(XPublicationCollector::class),
            Platform::Pinterest => app(PinterestPublicationCollector::class),
            Platform::YouTube => app(YouTubePublicationCollector::class),
            Platform::TikTok => app(TikTokPublicationCollector::class),
            default => throw AnalyticsCollectionException::unsupported("{$account->platform->value} publication history is not supported"),
        };
    }
}
