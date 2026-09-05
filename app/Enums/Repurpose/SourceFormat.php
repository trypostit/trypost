<?php

declare(strict_types=1);

namespace App\Enums\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;

/**
 * Which kind of video a repurpose watches for on its source account.
 *
 * A repurpose watches exactly one format. Someone who wants both their Reels
 * and their Stories replicated creates one repurpose per format, which is why
 * a source account is not limited to a single repurpose. Polling groups by
 * account, so two repurposes on one account still cost one round of calls.
 */
enum SourceFormat: string
{
    case Reel = 'reel';
    case Video = 'video';
    case Story = 'story';

    public function label(): string
    {
        return __("repurposes.formats.{$this->value}");
    }

    /**
     * Formats a source platform can be watched for.
     *
     * @return array<int, self>
     */
    public static function forPlatform(Platform $platform): array
    {
        return match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook, Platform::Facebook => [self::Reel, self::Video, self::Story],
            default => [],
        };
    }

    /**
     * Instagram reports `VIDEO` as the media type for a Reel and a feed video
     * alike, so the product type is the only thing telling them apart.
     */
    public function instagramProductType(): string
    {
        return match ($this) {
            self::Reel => 'REELS',
            self::Video => 'FEED',
            self::Story => 'STORY',
        };
    }

    /**
     * The content type a destination defaults to when this format lands on it,
     * so a picker can open on the closest match rather than on nothing.
     */
    public function defaultContentTypeFor(Platform $platform): ?ContentType
    {
        $candidates = match ($this) {
            self::Reel, self::Video => [ContentType::InstagramReel, ContentType::FacebookReel, ContentType::TikTokVideo, ContentType::YouTubeShort],
            self::Story => [ContentType::InstagramStory, ContentType::FacebookStory, ContentType::TikTokVideo, ContentType::YouTubeShort],
        };

        foreach ($candidates as $contentType) {
            if ($contentType->platform()->network() === $platform->network()) {
                return $contentType;
            }
        }

        return self::videoContentTypesFor($platform)[0] ?? null;
    }

    /**
     * Destination content types that accept a video on a platform. The module
     * only moves video, so anything else is never offered.
     *
     * @return array<int, ContentType>
     */
    public static function videoContentTypesFor(Platform $platform): array
    {
        return array_values(array_filter(
            ContentType::cases(),
            fn (ContentType $contentType): bool => $contentType->platform()->network() === $platform->network()
                && $contentType->supportsVideo(),
        ));
    }
}
