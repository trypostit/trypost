<?php

declare(strict_types=1);

namespace App\Enums\SocialAccount;

use App\Enums\Media\Type as MediaType;

enum Platform: string
{
    case LinkedIn = 'linkedin';
    case LinkedInPage = 'linkedin-page';
    case X = 'x';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case InstagramFacebook = 'instagram-facebook';
    case Threads = 'threads';
    case Pinterest = 'pinterest';
    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';
    case Telegram = 'telegram';
    case Discord = 'discord';

    /**
     * The social network this platform belongs to. Variants that represent the
     * same network (LinkedIn profile vs. company page, Instagram standalone vs.
     * Facebook-linked) collapse to one key so a workspace may connect only one
     * account per network.
     */
    public function network(): string
    {
        return match ($this) {
            self::LinkedIn, self::LinkedInPage => 'linkedin',
            self::Instagram, self::InstagramFacebook => 'instagram',
            default => $this->value,
        };
    }

    /**
     * All platform values that share this platform's network, used to enforce
     * the one-account-per-network rule across variants.
     *
     * @return array<int, string>
     */
    public function networkPlatformValues(): array
    {
        return array_values(array_map(
            fn (self $platform): string => $platform->value,
            array_filter(self::cases(), fn (self $platform): bool => $platform->network() === $this->network()),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::LinkedIn => 'LinkedIn',
            self::LinkedInPage => 'LinkedIn Page',
            self::X => 'X',
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube Shorts',
            self::Facebook => 'Facebook Page',
            self::Instagram => 'Instagram (Standalone)',
            self::InstagramFacebook => 'Instagram (Facebook Business)',
            self::Threads => 'Threads',
            self::Pinterest => 'Pinterest',
            self::Bluesky => 'Bluesky',
            self::Mastodon => 'Mastodon',
            self::Telegram => 'Telegram',
            self::Discord => 'Discord',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LinkedIn, self::LinkedInPage => '#0A66C2',
            self::X => '#000000',
            self::TikTok => '#000000',
            self::YouTube => '#FF0000',
            self::Facebook => '#1877F2',
            self::Instagram => '#E4405F',
            self::InstagramFacebook => '#E4405F',
            self::Threads => '#000000',
            self::Pinterest => '#E60023',
            self::Bluesky => '#0085FF',
            self::Mastodon => '#6364FF',
            self::Telegram => '#26A5E4',
            self::Discord => '#5865F2',
        };
    }

    public function allowedMediaTypes(): array
    {
        return match ($this) {
            self::LinkedIn, self::LinkedInPage => [MediaType::Image, MediaType::Video, MediaType::Document],
            self::X => [MediaType::Image, MediaType::Video],
            self::TikTok => [MediaType::Video],
            self::YouTube => [MediaType::Video],
            self::Facebook => [MediaType::Image, MediaType::Video],
            self::Instagram, self::InstagramFacebook => [MediaType::Image, MediaType::Video],
            self::Threads => [MediaType::Image, MediaType::Video],
            self::Pinterest => [MediaType::Image, MediaType::Video],
            self::Bluesky => [MediaType::Image, MediaType::Video],
            self::Mastodon => [MediaType::Image, MediaType::Video],
            self::Telegram => [MediaType::Image, MediaType::Video],
            self::Discord => [MediaType::Image, MediaType::Video],
        };
    }

    public function maxImages(): int
    {
        return match ($this) {
            self::LinkedIn, self::LinkedInPage => 10,
            self::X => 4,
            self::TikTok => 0,
            self::YouTube => 0,
            self::Facebook => 10,
            self::Instagram, self::InstagramFacebook => 10,
            self::Threads => 10,
            self::Pinterest => 5,
            self::Bluesky => 4,
            self::Mastodon => 4,
            self::Telegram => 10,
            self::Discord => 10,
        };
    }

    /**
     * Character cap the platform's API accepts for image alt text (accessibility
     * description), or null when the platform has no alt-text field. X, LinkedIn,
     * Instagram, Pinterest, and Discord use documented API maxes. Facebook,
     * Threads, Mastodon, and Bluesky document no limit, so a defensive cap is
     * used instead. Single source of truth — publishers truncate to this value,
     * never a literal.
     */
    public function altTextMaxLength(): ?int
    {
        return match ($this) {
            self::Bluesky => 2000,
            self::X => 1000,
            self::Mastodon => 1500,
            self::LinkedIn, self::LinkedInPage => 4086,
            self::Facebook => 1000,
            self::Instagram, self::InstagramFacebook => 1000,
            self::Threads => 1000,
            self::Pinterest => 500,
            self::Discord => 1024,
            self::TikTok, self::YouTube, self::Telegram => null,
        };
    }

    /**
     * Whether the platform's API accepts image alt text (accessibility
     * description) on published media.
     */
    public function supportsAltText(): bool
    {
        return $this->altTextMaxLength() !== null;
    }

    /**
     * Hard cap (in characters) the platform's API will accept. Going over this
     * means the post can't be published. Values are the documented API maxes:
     *
     *  - LinkedIn UGC: 3000 (`commentary` field)
     *  - X standard tweet: 280 (X Premium accepts 25K — ignored, conservative)
     *  - TikTok caption: 2200
     *  - YouTube Shorts: title=100, description=5000. We feed `content` to both
     *    (publisher derives title from the first line via `buildTitle`), and
     *    Shorts UX only shows ~100 chars before "more" — capping at 100 keeps
     *    posts appropriate for the format.
     *  - Facebook text status: 10000 (API allows 63206; we cap below
     *    that — 63k-char posts are unrealistic and emoji-heavy content
     *    risks overflowing the TEXT column's 65535-byte ceiling)
     *  - Instagram feed caption: 2200
     *  - Threads: 500
     *  - Pinterest pin description: 800 (title is 100, not modeled here)
     *  - Bluesky: 300 graphemes
     *  - Mastodon: 500 default; instances may be higher (we stay conservative)
     *  - Telegram: 4096 for a text message (media captions are capped at 1024,
     *    handled in the publisher by sending long text as its own message)
     */
    public function maxContentLength(): int
    {
        return match ($this) {
            self::LinkedIn, self::LinkedInPage => 3000,
            self::X => 280,
            self::TikTok => 2200,
            self::YouTube => 100,
            self::Facebook => 10000,
            self::Instagram, self::InstagramFacebook => 2200,
            self::Threads => 500,
            self::Pinterest => 800,
            self::Bluesky => 300,
            self::Mastodon => 500,
            self::Telegram => 4096,
            self::Discord => 2000,
        };
    }

    /**
     * Number of characters by which the given content exceeds this platform's
     * hard cap. Returns 0 when it fits. Single source of truth for content-
     * length checks — used both at schedule-validation time and at publish
     * time itself so the two paths can never drift apart.
     */
    public function contentOverflow(string $content): int
    {
        return max(0, mb_strlen($content) - $this->maxContentLength());
    }

    /**
     * Recommended target length (in characters) for AI-generated posts. This
     * is the engagement sweet spot — much shorter than the platform's hard
     * `maxContentLength()`. Use this to instruct the LLM at generation time;
     * use `maxContentLength()` for publish-time validation.
     */
    public function recommendedAiContentLength(): int
    {
        return match ($this) {
            // Microblogging — 70-200 char tweets perform best, leave hashtag room
            self::X, self::Bluesky => 220,
            // Threads/Mastodon — similar feel, slightly more relaxed
            self::Threads, self::Mastodon => 300,
            // LinkedIn — readable long-form sweet spot is ~1200-1500
            self::LinkedIn, self::LinkedInPage => 1200,
            // Instagram captions — most viewers expand only when interested,
            // 100-150 words performs best
            self::Instagram, self::InstagramFacebook => 600,
            // Facebook — short posts dominate the algorithm
            self::Facebook => 280,
            // Pinterest pin description — image does the work, keep it tight
            self::Pinterest => 200,
            // TikTok caption — the video carries the story
            self::TikTok => 150,
            // YouTube Shorts — fits within the 100-char title (with " #Shorts"
            // suffix taking 8 chars) so the same string works as title + desc
            self::YouTube => 80,
            // Telegram channel posts — short announcements read best
            self::Telegram => 400,
            // Discord — conversational community posts read best when concise
            self::Discord => 280,
        };
    }

    /**
     * @return array<string>
     */
    public function requiredPublishScopes(): array
    {
        return match ($this) {
            self::Instagram => ['instagram_business_content_publish'],
            self::InstagramFacebook => ['instagram_content_publish'],
            self::Facebook => ['pages_manage_posts'],
            self::TikTok => ['video.publish'],
            self::YouTube => ['https://www.googleapis.com/auth/youtube.upload'],
            self::LinkedIn => ['w_member_social'],
            self::LinkedInPage => ['w_organization_social'],
            self::X => ['tweet.write'],
            self::Threads => ['threads_content_publish'],
            self::Pinterest => ['pins:write'],
            self::Bluesky => [],
            self::Mastodon => ['write:statuses'],
            self::Telegram => [],
            self::Discord => [],
        };
    }

    public function supportsTextOnly(): bool
    {
        return match ($this) {
            self::LinkedIn, self::LinkedInPage => true,
            self::X => true,
            self::TikTok => false,
            self::YouTube => false,
            self::Facebook => true,
            self::Instagram, self::InstagramFacebook => false,
            self::Threads => true,
            self::Pinterest => false,
            self::Bluesky => true,
            self::Mastodon => true,
            self::Telegram => true,
            self::Discord => true,
        };
    }

    public function requiresContent(): bool
    {
        return match ($this) {
            self::YouTube => true,
            default => false,
        };
    }

    /**
     * Whether this platform refreshes by extending the access_token itself
     * (Instagram/Threads long-lived tokens) instead of exchanging a separate
     * refresh_token. Extension-model tokens cannot be refreshed once expired,
     * so they must be refreshed proactively while still valid — the opposite
     * of rotating refresh_token platforms, which we avoid refreshing until
     * they actually expire so we don't rotate a still-valid single-use token.
     */
    public function extendsAccessTokenOnRefresh(): bool
    {
        return match ($this) {
            self::Instagram, self::Threads => true,
            default => false,
        };
    }

    /**
     * The `platform` column values of the platforms that refresh by extending
     * their access token in place (Instagram and Threads — see
     * extendsAccessTokenOnRefresh), for use in database whereIn/whereNotIn
     * filters. Derived from extendsAccessTokenOnRefresh() so the two never drift.
     *
     * @return array<int, string>
     */
    public static function accessTokenExtendingPlatformValues(): array
    {
        return array_values(array_map(
            fn (self $platform): string => $platform->value,
            array_filter(self::cases(), fn (self $platform): bool => $platform->extendsAccessTokenOnRefresh()),
        ));
    }

    /**
     * The token lifetime, in seconds, to assume when the provider's OAuth
     * response omits expires_in. Each value is that network's own documented
     * default:
     *
     *  - X: a 2-hour access token.
     *  - Instagram / Threads: Meta's 60-day long-lived token.
     *
     * Networks that always return expires_in (LinkedIn, TikTok, YouTube,
     * Pinterest), whose refresh sets a fixed lifetime directly (Bluesky), or
     * whose tokens never expire (Facebook, Mastodon, Telegram, Discord) have no
     * fallback here and return null.
     */
    public function defaultTokenTtlSeconds(): ?int
    {
        return match ($this) {
            self::X => 7200,
            self::Instagram, self::Threads => 5184000,
            default => null,
        };
    }

    public function queue(): string
    {
        return 'social-'.$this->value;
    }

    /**
     * @return array<string>
     */
    public static function allQueues(): array
    {
        return array_map(fn (self $platform) => $platform->queue(), self::cases());
    }

    public function instagramGraphBaseUrl(): string
    {
        return match ($this) {
            self::InstagramFacebook => (string) config('trypost.platforms.instagram-facebook.graph_api'),
            default => (string) config('trypost.platforms.instagram.graph_api'),
        };
    }

    public function isEnabled(): bool
    {
        return config("trypost.platforms.{$this->value}.enabled", true);
    }

    /**
     * Whether this platform gets its own "Connect" card in the accounts grid.
     * LinkedIn company pages are reached through the unified LinkedIn connect
     * flow's identity picker, never a standalone card. The single LinkedIn card
     * stands for the whole network, so it shows whenever the profile OR the
     * company-page capability is enabled (self-hosters may run with only one).
     */
    public function isConnectable(): bool
    {
        return match ($this) {
            self::LinkedInPage => false,
            self::LinkedIn => self::LinkedIn->isEnabled() || self::LinkedInPage->isEnabled(),
            default => $this->isEnabled(),
        };
    }

    /**
     * Static, platform-specific data exposed to the frontend (e.g. TikTok privacy options,
     * compliance URLs). Returns an empty array for platforms with no extra config.
     *
     * @return array<string, mixed>
     */
    public function publishConfig(): array
    {
        return match ($this) {
            self::TikTok => [
                'privacyLevelOptions' => [
                    'PUBLIC_TO_EVERYONE',
                    'MUTUAL_FOLLOW_FRIENDS',
                    'FOLLOWER_OF_CREATOR',
                    'SELF_ONLY',
                ],
                'musicUsageConfirmationUrl' => 'https://www.tiktok.com/legal/page/global/music-usage-confirmation/en',
                'brandedContentPolicyUrl' => 'https://www.tiktok.com/legal/page/global/bc-policy/en',
            ],
            default => [],
        };
    }
}
