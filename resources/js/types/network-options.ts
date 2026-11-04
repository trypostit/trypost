import { ContentType } from '@/types/content-type';

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

/** The content type a thread reply publishes as, per network that chains replies (`ThreadReplies::replyContentType()`). */
export const THREAD_REPLY_CONTENT_TYPES: Readonly<Record<string, string>> = {
    bluesky: ContentType.BlueskyPost,
    mastodon: ContentType.MastodonPost,
    x: ContentType.XPost,
    linkedin: ContentType.LinkedInComment,
    'linkedin-page': ContentType.LinkedInComment,
};
export const THREAD_PLATFORMS: readonly string[] = Object.keys(
    THREAD_REPLY_CONTENT_TYPES,
);
/** How many replies a network's thread takes (`ThreadReplies::maxReplies()`): a LinkedIn post takes one comment. */
const THREAD_MAX_REPLIES: Readonly<Record<string, number>> = {
    bluesky: 24,
    mastodon: 24,
    x: 24,
    linkedin: 1,
    'linkedin-page': 1,
};
export const threadMaxReplies = (platform: string): number =>
    THREAD_MAX_REPLIES[platform] ?? 0;
/** Whether a network's thread reply is a comment on the post, not a post of its own. */
export const repliesAreComments = (platform: string): boolean =>
    THREAD_REPLY_CONTENT_TYPES[platform] === ContentType.LinkedInComment;
