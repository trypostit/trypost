import { ContentType, type ContentTypeValue } from '@/types/content-type';
import { Platform, type PlatformValue } from '@/types/platform';

export const YouTubePrivacyStatus = {
    Public: 'public',
    Unlisted: 'unlisted',
    Private: 'private',
} as const;

export const YouTubeLicense = {
    YouTube: 'youtube',
    CreativeCommon: 'creativeCommon',
} as const;

export const YOUTUBE_TITLE_MAX = 100;
export const THREADS_TOPIC_TAG_MAX = 50;

/** Networks that chain thread replies (`ThreadReplies::supports()` on the server). */
export const THREAD_PLATFORMS = [
    Platform.Bluesky,
    Platform.Mastodon,
    Platform.X,
    Platform.LinkedIn,
    Platform.LinkedInPage,
] as const;

export type ThreadPlatform = (typeof THREAD_PLATFORMS)[number];

const THREAD_PLATFORM_SET: ReadonlySet<PlatformValue> = new Set(THREAD_PLATFORMS);

/** Whether a network chains thread replies. */
export const isThreadPlatform = (
    platform: PlatformValue,
): platform is ThreadPlatform => THREAD_PLATFORM_SET.has(platform);

/** Networks whose thread replies can be polls (`ThreadReplies::replyPoll()` on the server). */
export const REPLY_POLL_PLATFORMS = [Platform.Mastodon, Platform.X] as const;

export type ReplyPollPlatform = (typeof REPLY_POLL_PLATFORMS)[number];

const REPLY_POLL_PLATFORM_SET: ReadonlySet<PlatformValue> = new Set(
    REPLY_POLL_PLATFORMS,
);

/** Whether a network's thread replies can be polls. */
export const isReplyPollPlatform = (
    platform: PlatformValue,
): platform is ReplyPollPlatform => REPLY_POLL_PLATFORM_SET.has(platform);

/** The content type a thread reply publishes as. */
export const THREAD_REPLY_CONTENT_TYPES: Readonly<
    Record<ThreadPlatform, ContentTypeValue>
> = {
    [Platform.Bluesky]: ContentType.BlueskyPost,
    [Platform.Mastodon]: ContentType.MastodonPost,
    [Platform.X]: ContentType.XPost,
    [Platform.LinkedIn]: ContentType.LinkedInComment,
    [Platform.LinkedInPage]: ContentType.LinkedInComment,
};

/** The content type a thread reply that is a poll publishes as. */
export const THREAD_REPLY_POLL_CONTENT_TYPES: Readonly<
    Record<ReplyPollPlatform, ContentTypeValue>
> = {
    [Platform.Mastodon]: ContentType.MastodonPoll,
    [Platform.X]: ContentType.XPoll,
};

/** How many replies a thread takes: a thread, or one comment on LinkedIn (`ThreadReplies::maxReplies()`). */
export const THREAD_MAX_REPLIES: Readonly<Record<ThreadPlatform, number>> = {
    [Platform.Bluesky]: 24,
    [Platform.Mastodon]: 24,
    [Platform.X]: 24,
    [Platform.LinkedIn]: 1,
    [Platform.LinkedInPage]: 1,
};
