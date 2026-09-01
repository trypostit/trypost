<?php

declare(strict_types=1);

namespace App\Enums\PostPlatform;

use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform as SocialPlatform;

enum ContentType: string
{
    // Instagram
    case InstagramFeed = 'instagram_feed';
    case InstagramReel = 'instagram_reel';
    case InstagramStory = 'instagram_story';

    // LinkedIn — one type per account kind; the publish format (single image,
    // multi-image, video, or PDF document) is inferred from the attached media.
    case LinkedInPost = 'linkedin_post';

    // LinkedIn Page
    case LinkedInPagePost = 'linkedin_page_post';

    // Facebook
    case FacebookPost = 'facebook_post';
    case FacebookReel = 'facebook_reel';
    case FacebookStory = 'facebook_story';

    // TikTok
    case TikTokVideo = 'tiktok_video';
    case TikTokPhoto = 'tiktok_photo';

    // YouTube
    case YouTubeShort = 'youtube_short';

    // X (Twitter)
    case XPost = 'x_post';

    // Threads
    case ThreadsPost = 'threads_post';

    // Pinterest
    case PinterestPin = 'pinterest_pin';
    case PinterestVideoPin = 'pinterest_video_pin';
    case PinterestCarousel = 'pinterest_carousel';

    // Bluesky
    case BlueskyPost = 'bluesky_post';

    // Mastodon
    case MastodonPost = 'mastodon_post';

    // Telegram
    case TelegramPost = 'telegram_post';

    // Discord
    case DiscordMessage = 'discord_message';

    // Google Business Profile
    case GoogleBusinessPost = 'google_business_post';

    /**
     * AI generation format for an Instagram carousel. Not a content type —
     * carousel posts are persisted as InstagramFeed.
     */
    public const CAROUSEL_FORMAT = 'instagram_carousel';

    public function label(): string
    {
        return match ($this) {
            self::InstagramFeed => 'Feed Post',
            self::InstagramReel => 'Reel',
            self::InstagramStory => 'Story',
            self::LinkedInPost, self::LinkedInPagePost => 'Post',
            self::FacebookPost => 'Post',
            self::FacebookReel => 'Reel',
            self::FacebookStory => 'Story',
            self::TikTokVideo => 'Video',
            self::TikTokPhoto => 'Photo carousel',
            self::YouTubeShort => 'Short',
            self::XPost => 'Post',
            self::ThreadsPost => 'Post',
            self::PinterestPin => 'Pin',
            self::PinterestVideoPin => 'Video Pin',
            self::PinterestCarousel => 'Carousel',
            self::BlueskyPost => 'Post',
            self::MastodonPost => 'Post',
            self::TelegramPost => 'Post',
            self::DiscordMessage => 'Message',
            self::GoogleBusinessPost => 'Post',
        };
    }

    public function description(): string
    {
        return (string) trans("posts.content_types.{$this->value}.description");
    }

    public function platform(): SocialPlatform
    {
        return match ($this) {
            self::InstagramFeed, self::InstagramReel, self::InstagramStory => SocialPlatform::Instagram,
            self::LinkedInPost => SocialPlatform::LinkedIn,
            self::LinkedInPagePost => SocialPlatform::LinkedInPage,
            self::FacebookPost, self::FacebookReel, self::FacebookStory => SocialPlatform::Facebook,
            self::TikTokVideo, self::TikTokPhoto => SocialPlatform::TikTok,
            self::YouTubeShort => SocialPlatform::YouTube,
            self::XPost => SocialPlatform::X,
            self::ThreadsPost => SocialPlatform::Threads,
            self::PinterestPin, self::PinterestVideoPin, self::PinterestCarousel => SocialPlatform::Pinterest,
            self::BlueskyPost => SocialPlatform::Bluesky,
            self::MastodonPost => SocialPlatform::Mastodon,
            self::TelegramPost => SocialPlatform::Telegram,
            self::DiscordMessage => SocialPlatform::Discord,
            self::GoogleBusinessPost => SocialPlatform::GoogleBusiness,
        };
    }

    /**
     * Image dimensions used by the AI generator for this format.
     * Single source of truth — `TemplateImageGenerator` reads from here.
     *
     * @return array{width: int, height: int}
     */
    public function aiImageDimensions(): array
    {
        return match ($this) {
            // Vertical 4:5 (Instagram preferred portrait, Threads mirrors it)
            self::InstagramFeed,
            self::ThreadsPost => ['width' => 1080, 'height' => 1350],

            // Square 1:1 (LinkedIn, X, Facebook, Bluesky, Mastodon)
            self::LinkedInPost,
            self::LinkedInPagePost,
            self::FacebookPost,
            self::XPost,
            self::BlueskyPost,
            self::MastodonPost => ['width' => 1080, 'height' => 1080],

            // Stories 9:16 (Instagram + Facebook)
            self::InstagramStory,
            self::FacebookStory => ['width' => 1080, 'height' => 1920],

            // Pinterest pin 2:3
            self::PinterestPin => ['width' => 1000, 'height' => 1500],

            // Default: 4:5 portrait (used for any other case)
            default => ['width' => 1080, 'height' => 1350],
        };
    }

    public function aspectRatio(): ?string
    {
        return match ($this) {
            self::InstagramFeed => '4:5',
            self::InstagramReel, self::InstagramStory => '9:16',
            self::FacebookReel, self::FacebookStory => '9:16',
            self::TikTokVideo, self::YouTubeShort => '9:16',
            self::TikTokPhoto => '1:1',
            self::PinterestPin, self::PinterestCarousel => '2:3',
            self::PinterestVideoPin => '9:16',
            default => null,
        };
    }

    public function maxMediaCount(): int
    {
        return match ($this) {
            self::InstagramFeed => 10,
            self::InstagramReel, self::InstagramStory => 1,
            self::LinkedInPost, self::LinkedInPagePost => 10,
            self::FacebookPost => 10,
            self::FacebookReel, self::FacebookStory => 1,
            self::TikTokVideo => 1,
            self::TikTokPhoto => 35,
            self::YouTubeShort => 1,
            self::XPost => 4,
            self::ThreadsPost => 10,
            self::PinterestPin, self::PinterestVideoPin => 1,
            self::PinterestCarousel => 5,
            self::BlueskyPost => 4,
            self::MastodonPost => 4,
            self::TelegramPost => 10,
            self::DiscordMessage => 10,
            self::GoogleBusinessPost => 1,
        };
    }

    /**
     * Maximum video duration in seconds for this content type, when the
     * platform publishes a hard cap via API. Null when unlimited, unknown,
     * or enforced dynamically (e.g. TikTok creator_info).
     *
     * Single source of truth for web (via Inertia shared props), REST API,
     * and MCP content-type listings.
     */
    public function maxVideoDurationSec(): ?int
    {
        return match ($this) {
            self::InstagramFeed => 60,
            self::InstagramReel => 15 * 60,
            self::InstagramStory => 60,
            self::FacebookPost => 240 * 60,
            self::FacebookReel => 90,
            self::FacebookStory => 60,
            self::LinkedInPost, self::LinkedInPagePost => 10 * 60,
            self::YouTubeShort => 3 * 60,
            self::PinterestVideoPin => 15 * 60,
            self::XPost => 140,
            self::ThreadsPost => 5 * 60,
            self::BlueskyPost => 60,
            default => null,
        };
    }

    /**
     * Minimum attachments required (0 = no floor beyond requiresMedia()).
     */
    public function minMediaCount(): int
    {
        return match ($this) {
            self::PinterestCarousel => 2,
            self::TikTokPhoto => 1,
            default => 0,
        };
    }

    /**
     * Whether animated GIFs are accepted (kept as GIF, not flattened to JPEG).
     */
    public function acceptsGif(): bool
    {
        return match ($this) {
            self::XPost, self::BlueskyPost, self::MastodonPost,
            self::DiscordMessage, self::TelegramPost => true,
            default => false,
        };
    }

    /**
     * Per-type image size cap in bytes, capped at the global upload hard limit
     * (trypost.media.max_size_mb.image). Null when images are not accepted or
     * the platform has no tighter editor-side limit than that hard cap.
     */
    public function maxImageBytes(): ?int
    {
        $bytes = match ($this) {
            self::InstagramFeed, self::InstagramStory => self::bytesFromMb(8),
            self::FacebookPost => self::bytesFromMb(4),
            self::LinkedInPost, self::LinkedInPagePost => self::bytesFromMb(5),
            self::PinterestPin, self::PinterestCarousel => self::bytesFromMb(20),
            self::XPost => self::bytesFromMb(5),
            self::ThreadsPost => self::bytesFromMb(8),
            self::MastodonPost => self::bytesFromMb(10),
            default => null,
        };

        return self::capToHardLimit($bytes, MediaType::Image);
    }

    /**
     * Per-type video size cap in bytes, capped at the global upload hard limit
     * (trypost.media.max_size_mb.video). Null when videos are not accepted or
     * the platform has no tighter editor-side limit than that hard cap.
     */
    public function maxVideoBytes(): ?int
    {
        $bytes = match ($this) {
            self::InstagramFeed, self::InstagramStory => self::bytesFromMb(100),
            self::InstagramReel => self::bytesFromGb(1),
            self::FacebookPost => self::bytesFromGb(10),
            self::FacebookReel, self::FacebookStory => self::bytesFromGb(1),
            self::LinkedInPost, self::LinkedInPagePost => self::bytesFromGb(5),
            self::YouTubeShort => self::bytesFromGb(256),
            self::PinterestVideoPin => self::bytesFromGb(2),
            self::XPost => self::bytesFromMb(512),
            self::ThreadsPost => self::bytesFromGb(1),
            self::BlueskyPost => self::bytesFromMb(100),
            self::MastodonPost => self::bytesFromMb(40),
            default => null,
        };

        return self::capToHardLimit($bytes, MediaType::Video);
    }

    /**
     * Per-type PDF size cap in bytes, capped at the global upload hard limit.
     * Null when documents are not accepted.
     */
    public function maxDocumentBytes(): ?int
    {
        $bytes = match ($this) {
            self::LinkedInPost, self::LinkedInPagePost => self::bytesFromMb(100),
            default => null,
        };

        return self::capToHardLimit($bytes, MediaType::Document);
    }

    /**
     * Soft aspect-ratio window used by the Vue cropper / media picker.
     *
     * @return array{min: float, max: float}|null
     */
    public function aspectRatioBounds(): ?array
    {
        return match ($this) {
            self::InstagramFeed => ['min' => 0.8, 'max' => 1.91],
            self::InstagramReel, self::InstagramStory,
            self::FacebookReel, self::FacebookStory,
            self::YouTubeShort => ['min' => 0.5, 'max' => 0.6],
            default => null,
        };
    }

    /**
     * Whether the editor should auto-fit still images into the story frame.
     */
    public function autoFitsImage(): bool
    {
        return $this === self::InstagramStory;
    }

    /**
     * Full media-rule payload for the Vue editor (and any other consumer).
     * Shared once via Inertia — do not re-hardcode these numbers in JS.
     *
     * @return array{
     *     max_files: int,
     *     min_files: int|null,
     *     accept_images: bool,
     *     accept_videos: bool,
     *     accept_documents: bool,
     *     requires_media: bool,
     *     accepts_gif: bool,
     *     forbids_mixed_media: bool,
     *     max_image_bytes: int|null,
     *     max_video_bytes: int|null,
     *     max_document_bytes: int|null,
     *     max_video_duration_sec: int|null,
     *     aspect_ratio_min: float|null,
     *     aspect_ratio_max: float|null,
     *     auto_fits_image: bool
     * }
     */
    public function mediaRules(): array
    {
        $bounds = $this->aspectRatioBounds();
        $minFiles = $this->minMediaCount();

        return [
            'max_files' => $this->maxMediaCount(),
            'min_files' => $minFiles > 0 ? $minFiles : null,
            'accept_images' => $this->supportsImage(),
            'accept_videos' => $this->supportsVideo(),
            'accept_documents' => $this->supportsDocument(),
            'requires_media' => $this->requiresMedia(),
            'accepts_gif' => $this->acceptsGif(),
            'forbids_mixed_media' => ! $this->supportsMixedMedia(),
            'max_image_bytes' => $this->maxImageBytes(),
            'max_video_bytes' => $this->maxVideoBytes(),
            'max_document_bytes' => $this->maxDocumentBytes(),
            'max_video_duration_sec' => $this->maxVideoDurationSec(),
            'aspect_ratio_min' => $bounds['min'] ?? null,
            'aspect_ratio_max' => $bounds['max'] ?? null,
            'auto_fits_image' => $this->autoFitsImage(),
        ];
    }

    /**
     * Content-type row for REST / MCP listings (agents + API clients).
     * Keep in sync with mediaRules() capability fields — do not omit mins.
     *
     * @return array{
     *     value: string,
     *     label: string,
     *     description: string,
     *     max_media_count: int,
     *     min_media_count: int,
     *     requires_media: bool,
     *     accept_images: bool,
     *     accept_videos: bool,
     *     accept_documents: bool,
     *     accepts_gif: bool,
     *     forbids_mixed_media: bool,
     *     max_video_duration_sec: int|null,
     *     max_image_bytes: int|null,
     *     max_video_bytes: int|null,
     *     max_document_bytes: int|null
     * }
     */
    public function toListingArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'max_media_count' => $this->maxMediaCount(),
            'min_media_count' => $this->minMediaCount(),
            'requires_media' => $this->requiresMedia(),
            'accept_images' => $this->supportsImage(),
            'accept_videos' => $this->supportsVideo(),
            'accept_documents' => $this->supportsDocument(),
            'accepts_gif' => $this->acceptsGif(),
            'forbids_mixed_media' => ! $this->supportsMixedMedia(),
            'max_video_duration_sec' => $this->maxVideoDurationSec(),
            'max_image_bytes' => $this->maxImageBytes(),
            'max_video_bytes' => $this->maxVideoBytes(),
            'max_document_bytes' => $this->maxDocumentBytes(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function mediaRulesForFrontend(): array
    {
        $rules = [];

        foreach (self::cases() as $type) {
            $rules[$type->value] = $type->mediaRules();
        }

        return $rules;
    }

    private static function bytesFromMb(int $megabytes): int
    {
        return $megabytes * 1024 * 1024;
    }

    private static function bytesFromGb(int $gigabytes): int
    {
        return $gigabytes * 1024 * 1024 * 1024;
    }

    /**
     * Never advertise a soft platform ceiling above what trypost.media allows
     * on upload (web / API / MCP signed URL).
     */
    private static function capToHardLimit(?int $platformBytes, MediaType $type): ?int
    {
        if ($platformBytes === null) {
            return null;
        }

        return min($platformBytes, $type->maxSizeInBytes());
    }

    public function supportsVideo(): bool
    {
        return match ($this) {
            self::InstagramFeed, self::InstagramReel, self::InstagramStory => true,
            self::LinkedInPost, self::LinkedInPagePost => true,
            self::FacebookPost, self::FacebookReel, self::FacebookStory => true,
            self::TikTokVideo => true,
            self::TikTokPhoto => false,
            self::YouTubeShort => true,
            self::XPost => true,
            self::ThreadsPost => true,
            self::PinterestVideoPin => true,
            self::PinterestPin, self::PinterestCarousel => false,
            self::BlueskyPost => true,
            self::MastodonPost => true,
            self::TelegramPost => true,
            self::DiscordMessage => true,
            self::GoogleBusinessPost => false,
        };
    }

    public function supportsImage(): bool
    {
        return match ($this) {
            self::InstagramReel => false,
            self::FacebookReel, self::FacebookStory => false,
            self::TikTokVideo => false,
            self::TikTokPhoto => true,
            self::YouTubeShort => false,
            self::PinterestVideoPin => false,
            default => true,
        };
    }

    /**
     * Whether this content type can carry a PDF document (the swipeable LinkedIn
     * document/carousel). LinkedIn infers the document format from a PDF being
     * attached; the document is always the only attachment (see the media rule).
     */
    public function supportsDocument(): bool
    {
        return match ($this) {
            self::LinkedInPost, self::LinkedInPagePost => true,
            default => false,
        };
    }

    /**
     * Whether a single post may carry images and a video together. Most
     * targets accept only one or the other; this is conservative and lists
     * only the types we've confirmed reject mixed media (e.g. a Bluesky post
     * embed is `images` XOR `video`, never both).
     */
    public function supportsMixedMedia(): bool
    {
        return match ($this) {
            self::BlueskyPost => false,
            // LinkedIn publishes images XOR one video XOR one document — never mixed.
            self::LinkedInPost, self::LinkedInPagePost => false,
            default => true,
        };
    }

    /**
     * Whether this content type carries a text caption visible to viewers.
     * Stories are image-overlay only — viewers don't see a separate caption.
     */
    public function supportsCaption(): bool
    {
        return match ($this) {
            self::InstagramStory, self::FacebookStory => false,
            default => true,
        };
    }

    public function requiresMedia(): bool
    {
        return match ($this) {
            self::LinkedInPost, self::LinkedInPagePost => false,
            self::XPost => false,
            self::ThreadsPost => false,
            self::BlueskyPost => false,
            self::MastodonPost => false,
            self::TelegramPost => false,
            self::FacebookPost => false,
            self::DiscordMessage => false,
            self::GoogleBusinessPost => false,
            default => true,
        };
    }

    /**
     * Content types that the AI generator currently supports. Reels/stories/
     * videos are excluded because the AI flow only produces text + images.
     *
     * @return array<self>
     */
    public static function aiSupported(): array
    {
        return [
            self::InstagramFeed,
            self::InstagramStory,
            self::LinkedInPost,
            self::LinkedInPagePost,
            self::XPost,
            self::ThreadsPost,
            self::BlueskyPost,
            self::MastodonPost,
            self::FacebookPost,
            self::PinterestPin,
            self::PinterestCarousel,
        ];
    }

    /**
     * Platforms this content_type can be assigned to. Most content types are
     * tied to a single platform; Instagram-family types (feed/carousel/reel/
     * story) work for both `Instagram` (Basic Display) and `InstagramFacebook`
     * (Business via Facebook Page) accounts.
     *
     * @return array<SocialPlatform>
     */
    public function compatiblePlatforms(): array
    {
        $primary = $this->platform();

        return match ($primary) {
            SocialPlatform::Instagram => [SocialPlatform::Instagram, SocialPlatform::InstagramFacebook],
            default => [$primary],
        };
    }

    /**
     * Get all content types for a specific platform. Treats InstagramFacebook
     * as an alias for Instagram so both flavors get the same content types.
     *
     * @return array<self>
     */
    public static function forPlatform(SocialPlatform $platform): array
    {
        $effective = match ($platform) {
            SocialPlatform::InstagramFacebook => SocialPlatform::Instagram,
            default => $platform,
        };

        return array_filter(
            self::cases(),
            fn (self $type) => $type->platform() === $effective
        );
    }

    /**
     * Get the default content type for a platform.
     */
    public static function defaultFor(SocialPlatform $platform): self
    {
        return match ($platform) {
            SocialPlatform::Instagram, SocialPlatform::InstagramFacebook => self::InstagramFeed,
            SocialPlatform::LinkedIn => self::LinkedInPost,
            SocialPlatform::LinkedInPage => self::LinkedInPagePost,
            SocialPlatform::Facebook => self::FacebookPost,
            SocialPlatform::TikTok => self::TikTokVideo,
            SocialPlatform::YouTube => self::YouTubeShort,
            SocialPlatform::X => self::XPost,
            SocialPlatform::Threads => self::ThreadsPost,
            SocialPlatform::Pinterest => self::PinterestPin,
            SocialPlatform::Bluesky => self::BlueskyPost,
            SocialPlatform::Mastodon => self::MastodonPost,
            SocialPlatform::Telegram => self::TelegramPost,
            SocialPlatform::Discord => self::DiscordMessage,
            SocialPlatform::GoogleBusiness => self::GoogleBusinessPost,
        };
    }
}
