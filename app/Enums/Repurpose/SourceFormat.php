<?php

declare(strict_types=1);

namespace App\Enums\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;

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
     * @return array<int, self>
     */
    public static function forPlatform(Platform $platform): array
    {
        return match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook, Platform::Facebook => [self::Reel, self::Video, self::Story],
            default => [],
        };
    }

    public function instagramProductType(): string
    {
        return match ($this) {
            self::Reel => 'REELS',
            self::Video => 'FEED',
            self::Story => 'STORY',
        };
    }

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
