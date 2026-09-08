<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use InvalidArgumentException;

class SourceFetcherFactory
{
    public function for(SocialAccount $account): SourceFetcher
    {
        return match ($account->platform) {
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramSourceFetcher::class),
            Platform::Facebook => app(FacebookSourceFetcher::class),
            default => throw new InvalidArgumentException("{$account->platform->value} cannot be a repurpose source."),
        };
    }

    /**
     * @return array<int, Platform>
     */
    public static function supportedPlatforms(): array
    {
        return [Platform::Instagram, Platform::InstagramFacebook, Platform::Facebook];
    }
}
