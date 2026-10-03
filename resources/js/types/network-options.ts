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

export const THREAD_MAX_REPLIES = 24;
export const THREAD_PLATFORMS: readonly string[] = ['bluesky', 'mastodon'];
