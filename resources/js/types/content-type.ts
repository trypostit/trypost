export const ContentType = {
    InstagramFeed: 'instagram_feed',
    InstagramStory: 'instagram_story',
    InstagramReel: 'instagram_reel',
    LinkedInPost: 'linkedin_post',
    LinkedInPagePost: 'linkedin_page_post',
    /** The comment a LinkedIn post publishes as its one thread reply; never a post's own type. */
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
    PinterestPin: 'pinterest_pin',
    PinterestVideoPin: 'pinterest_video_pin',
    PinterestCarousel: 'pinterest_carousel',
    BlueskyPost: 'bluesky_post',
    MastodonPost: 'mastodon_post',
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
    [ContentType.XPoll]: 'posts.form.x.poll.attachments',
};

/** Content types that carry no media: the composer hides the media tray. */
export const MEDIALESS_CONTENT_TYPES: ReadonlySet<string> = new Set(
    Object.keys(MEDIALESS_CONTENT_TYPE_REASONS),
);
