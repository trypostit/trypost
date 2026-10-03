<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Enums\SocialAccount\Platform;

class ExternalMediaResolverFactory
{
    public function for(Platform $platform): ExternalMediaResolver
    {
        return match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramMediaResolver::class),
            Platform::Threads => app(ThreadsMediaResolver::class),
            Platform::Facebook => app(FacebookMediaResolver::class),
            Platform::X => app(XMediaResolver::class),
            Platform::Mastodon => app(MastodonMediaResolver::class),
            Platform::Bluesky => app(BlueskyMediaResolver::class),
            Platform::Pinterest => app(PinterestMediaResolver::class),
            default => app(CoverOnlyResolver::class),
        };
    }
}
