export const ContentType = {
    InstagramFeed: 'instagram_feed',
    InstagramStory: 'instagram_story',
    InstagramReel: 'instagram_reel',
    InstagramComment: 'instagram_comment',
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

const CONTENT_TYPE_VALUES = Object.values(ContentType);

/** Whether a value read as text (a radio, a URL) is one of the content types. */
export const isContentType = (value: string): value is ContentTypeValue => {
    return CONTENT_TYPE_VALUES.some((contentType) => contentType === value);
};

/** Content types published without a caption: the composer hides the text, it is not deleted. */
export const CAPTIONLESS_CONTENT_TYPES: ReadonlySet<string> = new Set([
    ContentType.FacebookStory,
    ContentType.InstagramStory,
]);

/** Content types that carry no media, with why a card holding media cannot switch to one (`maxMediaCount()` 0 on the server). */
export const MEDIALESS_CONTENT_TYPE_REASONS: Readonly<
    Partial<Record<ContentTypeValue, string>>
> = {
    [ContentType.ThreadsGhostPost]: 'posts.form.threads.ghost_post_attachments',
    [ContentType.XPoll]: 'posts.form.poll.attachments',
    [ContentType.MastodonPoll]: 'posts.form.poll.attachments',
    [ContentType.ThreadsPoll]: 'posts.form.poll.attachments',
    [ContentType.InstagramComment]: 'posts.form.thread.comment_attachments',
    [ContentType.LinkedInComment]: 'posts.form.thread.comment_attachments',
};

export type PostFormTranslationKey = `posts.form.${string}`;

/**
 * Post types that take no thread replies on a network that otherwise does,
 * with why (each network's `replies.standalone_types` on the server).
 */
export const STANDALONE_CONTENT_TYPES: {
    readonly [Type in ContentTypeValue]?: PostFormTranslationKey;
} = {
    [ContentType.InstagramStory]: 'posts.form.thread.instagram_story',
    [ContentType.ThreadsGhostPost]: 'posts.form.thread.ghost_post',
};

type PollContentType = Extract<ContentTypeValue, `${string}_poll`>;
type NonPollContentType = Exclude<ContentTypeValue, PollContentType>;

/** Each network's poll with the plain post it goes back to when its poll is removed. */
export const POLL_POST_CONTENT_TYPES = {
    [ContentType.XPoll]: ContentType.XPost,
    [ContentType.MastodonPoll]: ContentType.MastodonPost,
    [ContentType.ThreadsPoll]: ContentType.ThreadsPost,
} as const satisfies Record<PollContentType, NonPollContentType>;

export const isPollContentType = (
    value: ContentTypeValue,
): value is PollContentType => {
    return Object.hasOwn(POLL_POST_CONTENT_TYPES, value);
};

/** Content types that are a poll (`ContentType::isPoll()` on the server). */
export const POLL_CONTENT_TYPES: ReadonlySet<ContentTypeValue> = new Set(
    CONTENT_TYPE_VALUES.filter(isPollContentType),
);

/** Content types that carry no media: the composer hides the media tray. */
export const MEDIALESS_CONTENT_TYPES: ReadonlySet<string> = new Set(
    Object.keys(MEDIALESS_CONTENT_TYPE_REASONS),
);
