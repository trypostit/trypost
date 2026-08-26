<?php

declare(strict_types=1);

namespace App\Support\Social;

/**
 * Directory name a publisher hosts a pull-from-URL derivative in, shared
 * between each writer (CropsImageForAspectRatio, TikTokPhotoDerivativeCleaner)
 * and the scheduled prune command (PruneSocialMediaDerivatives) so they
 * cannot drift. Add a new network's directory here - nowhere else - when it
 * starts hosting derivatives a platform fetches asynchronously.
 */
final class SocialMediaDerivativeDirectory
{
    public const string CROPS = 'social-crops';

    public const string TIKTOK_PHOTOS = 'social-tiktok-photos';

    /**
     * Every directory a publisher hosts a pull-from-URL derivative in.
     *
     * @var list<string>
     */
    public const array ALL = [
        self::CROPS,
        self::TIKTOK_PHOTOS,
    ];
}
