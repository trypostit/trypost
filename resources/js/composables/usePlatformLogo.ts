import { derivesContentType } from '@/lib/derivedContentType';
import { filled } from '@/lib/helpers';
import { ContentType, type ContentTypeValue } from '@/types/content-type';
import { Platform } from '@/types/platform';

const PLATFORM_LOGOS: Record<string, string> = {
    [Platform.LinkedIn]: '/images/accounts/linkedin.png',
    [Platform.LinkedInPage]: '/images/accounts/linkedin.png',
    [Platform.X]: '/images/accounts/x.png',
    [Platform.TikTok]: '/images/accounts/tiktok.png',
    [Platform.Instagram]: '/images/accounts/instagram.png',
    [Platform.InstagramFacebook]: '/images/accounts/instagram.png',
    [Platform.Facebook]: '/images/accounts/facebook.png',
    [Platform.YouTube]: '/images/accounts/youtube.png',
    [Platform.Threads]: '/images/accounts/threads.png',
    [Platform.Bluesky]: '/images/accounts/bluesky.png',
    [Platform.Pinterest]: '/images/accounts/pinterest.png',
    [Platform.Mastodon]: '/images/accounts/mastodon.png',
    [Platform.Telegram]: '/images/accounts/telegram.png',
    [Platform.Discord]: '/images/accounts/discord.png',
    [Platform.GoogleBusiness]: '/images/accounts/google_business.png',
};

const PLATFORM_LABELS: Record<string, string> = {
    [Platform.LinkedIn]: 'LinkedIn',
    [Platform.LinkedInPage]: 'LinkedIn Page',
    [Platform.X]: 'X',
    [Platform.TikTok]: 'TikTok',
    [Platform.Instagram]: 'Instagram',
    [Platform.InstagramFacebook]: 'Instagram',
    [Platform.Facebook]: 'Facebook',
    [Platform.YouTube]: 'YouTube',
    [Platform.Threads]: 'Threads',
    [Platform.Bluesky]: 'Bluesky',
    [Platform.Pinterest]: 'Pinterest',
    [Platform.Mastodon]: 'Mastodon',
    [Platform.Telegram]: 'Telegram',
    [Platform.Discord]: 'Discord',
    [Platform.GoogleBusiness]: 'Google Business Profile',
};

const PLATFORM_CONTENT_TYPES: Readonly<Record<string, ContentTypeValue[]>> = {
    [Platform.Instagram]: [ContentType.InstagramFeed, ContentType.InstagramReel, ContentType.InstagramStory],
    [Platform.InstagramFacebook]: [
        ContentType.InstagramFeed,
        ContentType.InstagramReel,
        ContentType.InstagramStory,
    ],
    [Platform.LinkedIn]: [ContentType.LinkedInPost],
    [Platform.LinkedInPage]: [ContentType.LinkedInPagePost],
    [Platform.Facebook]: [ContentType.FacebookPost, ContentType.FacebookReel, ContentType.FacebookStory],
    [Platform.TikTok]: [ContentType.TikTokVideo, ContentType.TikTokPhoto],
    [Platform.YouTube]: [ContentType.YouTubeShort],
    [Platform.X]: [ContentType.XPost, ContentType.XPoll],
    [Platform.Threads]: [ContentType.ThreadsPost, ContentType.ThreadsGhostPost, ContentType.ThreadsPoll],
    [Platform.Pinterest]: [ContentType.PinterestPin, ContentType.PinterestVideoPin, ContentType.PinterestCarousel],
    [Platform.Bluesky]: [ContentType.BlueskyPost],
    [Platform.Mastodon]: [ContentType.MastodonPost, ContentType.MastodonPoll],
    [Platform.Telegram]: [ContentType.TelegramPost],
    [Platform.Discord]: [ContentType.DiscordMessage],
    [Platform.GoogleBusiness]: [ContentType.GoogleBusinessPost],
};

const PLATFORM_CONTENT_TYPE_BADGES: Readonly<Record<string, ContentTypeValue[]>> = {
    [Platform.Instagram]: [ContentType.InstagramReel, ContentType.InstagramStory],
    [Platform.InstagramFacebook]: [ContentType.InstagramReel, ContentType.InstagramStory],
    [Platform.Facebook]: [ContentType.FacebookReel, ContentType.FacebookStory],
    [Platform.Threads]: [ContentType.ThreadsGhostPost, ContentType.ThreadsPoll],
    [Platform.X]: [ContentType.XPoll],
    [Platform.Mastodon]: [ContentType.MastodonPoll],
};

export interface ContentTypeOption {
    value: ContentTypeValue;
    labelKey: string;
    disabledReasonKey?: string;
}

export const getPlatformLogo = (platform: string): string =>
    PLATFORM_LOGOS[platform] ?? PLATFORM_LOGOS[Platform.LinkedIn];

export const getPlatformLabel = (platform: string): string =>
    PLATFORM_LABELS[platform] ?? platform;

const translationKeyFor = (contentType: string): string => `posts.content_types.${contentType}.label`;

export const getContentTypeOptions = (platform: string): ContentTypeOption[] =>
    (PLATFORM_CONTENT_TYPES[platform] ?? []).map((value) => ({
        value,
        labelKey: translationKeyFor(value),
    }));

const LONE_CONTENT_TYPE_RADIO_PLATFORMS = new Set(['youtube']);

/**
 * The content types the composer offers as radios. A single type shows only
 * where it gets a lone radio (YouTube "Short"); networks that derive their type
 * from the media (Pinterest, TikTok) offer none.
 */
export const getPickableContentTypeOptions = (
    platform: string,
): ContentTypeOption[] => {
    const options = derivesContentType(platform)
        ? []
        : getContentTypeOptions(platform);

    return options.length > 1 || LONE_CONTENT_TYPE_RADIO_PLATFORMS.has(platform)
        ? options
        : [];
};

/** Whether the user picks a format on this platform, or it only has one. */
export const hasMultipleContentTypes = (platform: string): boolean =>
    getContentTypeOptions(platform).length > 1;

/** Translation key for a format explicitly shown as a badge on post cards. */
export const getContentTypeBadgeKey = (
    platform: string,
    contentType: ContentTypeValue | null,
): string | null => {
    if (!filled(contentType)) {
        return null;
    }

    if (!PLATFORM_CONTENT_TYPE_BADGES[platform]?.includes(contentType)) {
        return null;
    }

    return translationKeyFor(contentType);
};
