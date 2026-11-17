export const ContentType = {
    InstagramFeed: 'instagram_feed',
    InstagramStory: 'instagram_story',
    InstagramReel: 'instagram_reel',
    LinkedInPost: 'linkedin_post',
    LinkedInPagePost: 'linkedin_page_post',
    LinkedInComment: 'linkedin_comment',
    FacebookPost: 'facebook_post',
    FacebookReel: 'facebook_reel',
    FacebookStory: 'facebook_story',
    TikTokVideo: 'tiktok_video',
    TikTokPhoto: 'tiktok_photo',
    YouTubeShort: 'youtube_short',
    XPost: 'x_post',
    XPoll: 'x_poll',
    ThreadsPost: 'threads_post',
    ThreadsGhostPost: 'threads_ghost_post',
    ThreadsPoll: 'threads_poll',
    PinterestPin: 'pinterest_pin',
    PinterestVideoPin: 'pinterest_video_pin',
    PinterestCarousel: 'pinterest_carousel',
    BlueskyPost: 'bluesky_post',
    MastodonPost: 'mastodon_post',
    MastodonPoll: 'mastodon_poll',
    TelegramPost: 'telegram_post',
    DiscordMessage: 'discord_message',
    GoogleBusinessPost: 'google_business_post',
} as const;

export type ContentTypeValue = (typeof ContentType)[keyof typeof ContentType];

/** Content types published without a caption: the composer hides the text, it is not deleted. */
export const CAPTIONLESS_CONTENT_TYPES: ReadonlySet<string> = new Set([
    ContentType.FacebookStory,
    ContentType.InstagramStory,
]);

/** Content types that carry no media, with why a card holding media cannot switch to one (`maxMediaCount()` 0 on the server). */
export const MEDIALESS_CONTENT_TYPE_REASONS: Readonly<Record<string, string>> = {
    [ContentType.ThreadsGhostPost]: 'posts.form.threads.ghost_post_attachments',
    [ContentType.XPoll]: 'posts.form.poll.attachments',
    [ContentType.MastodonPoll]: 'posts.form.poll.attachments',
    [ContentType.ThreadsPoll]: 'posts.form.poll.attachments',
    [ContentType.LinkedInComment]: 'posts.form.thread.comment_attachments',
};

/**
 * Each network's poll (`ContentType::isPoll()` on the server) with the plain
 * post it goes back to when its poll is removed.
 */
export const POLL_POST_CONTENT_TYPES: Readonly<Record<string, string>> = {
    [ContentType.XPoll]: ContentType.XPost,
    [ContentType.MastodonPoll]: ContentType.MastodonPost,
    [ContentType.ThreadsPoll]: ContentType.ThreadsPost,
};

/** Content types that are a poll: the post text is the question and there is no media. */
export const POLL_CONTENT_TYPES: ReadonlySet<string> = new Set(
    Object.keys(POLL_POST_CONTENT_TYPES),
);

/** Content types that carry no media: the composer hides the media tray. */
export const MEDIALESS_CONTENT_TYPES: ReadonlySet<string> = new Set(
    Object.keys(MEDIALESS_CONTENT_TYPE_REASONS),
);
