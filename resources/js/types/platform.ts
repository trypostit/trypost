export const Platform = {
    LinkedIn: 'linkedin',
    LinkedInPage: 'linkedin-page',
    X: 'x',
    TikTok: 'tiktok',
    YouTube: 'youtube',
    GoogleBusinessProfile: 'google-business-profile',
    Facebook: 'facebook',
    Instagram: 'instagram',
    InstagramFacebook: 'instagram-facebook',
    Threads: 'threads',
    Pinterest: 'pinterest',
    Bluesky: 'bluesky',
    Mastodon: 'mastodon',
    Telegram: 'telegram',
    Discord: 'discord',
} as const;

export type PlatformValue = (typeof Platform)[keyof typeof Platform];
