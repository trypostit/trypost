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
            default => throw AnalyticsCollectionException::unsupported("{$account->platform->value} publication history is not handled by a Meta collector"),
        };
    }
}
