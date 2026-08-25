const PLATFORM_LOGOS: Record<string, string> = {
    linkedin: '/images/accounts/linkedin.png',
    'linkedin-page': '/images/accounts/linkedin.png',
    x: '/images/accounts/x.png',
    tiktok: '/images/accounts/tiktok.png',
    instagram: '/images/accounts/instagram.png',
    'instagram-facebook': '/images/accounts/instagram.png',
    facebook: '/images/accounts/facebook.png',
    youtube: '/images/accounts/youtube.png',
    threads: '/images/accounts/threads.png',
    bluesky: '/images/accounts/bluesky.png',
    pinterest: '/images/accounts/pinterest.png',
    mastodon: '/images/accounts/mastodon.png',
    telegram: '/images/accounts/telegram.png',
    discord: '/images/accounts/discord.png',
};

const PLATFORM_LABELS: Record<string, string> = {
    linkedin: 'LinkedIn',
    'linkedin-page': 'LinkedIn Page',
    x: 'X',
    tiktok: 'TikTok',
    instagram: 'Instagram',
    'instagram-facebook': 'Instagram',
    facebook: 'Facebook',
    youtube: 'YouTube',
    threads: 'Threads',
    bluesky: 'Bluesky',
    pinterest: 'Pinterest',
    mastodon: 'Mastodon',
    telegram: 'Telegram',
    discord: 'Discord',
};

const PLATFORM_CONTENT_TYPES: Record<string, string[]> = {
    instagram: ['instagram_feed', 'instagram_reel', 'instagram_story'],
    'instagram-facebook': [
        'instagram_feed',
        'instagram_reel',
        'instagram_story',
    ],
    linkedin: ['linkedin_post'],
    'linkedin-page': ['linkedin_page_post'],
    facebook: ['facebook_post', 'facebook_reel', 'facebook_story'],
    tiktok: ['tiktok_video'],
    youtube: ['youtube_short'],
    x: ['x_post'],
    threads: ['threads_post'],
    pinterest: ['pinterest_pin', 'pinterest_video_pin', 'pinterest_carousel'],
    bluesky: ['bluesky_post'],
    mastodon: ['mastodon_post'],
    telegram: ['telegram_post'],
    discord: ['discord_message'],
};

export interface ContentTypeOption {
    value: string;
    labelKey: string;
}

const PLATFORM_THEMES: Record<string, { bg: string; rotate: string }> = {
    instagram: { bg: 'bg-pink-200', rotate: '-rotate-2' },
    'instagram-facebook': { bg: 'bg-pink-200', rotate: '-rotate-2' },
    facebook: { bg: 'bg-sky-200', rotate: 'rotate-1' },
    linkedin: { bg: 'bg-blue-200', rotate: '-rotate-1' },
    'linkedin-page': { bg: 'bg-blue-200', rotate: '-rotate-1' },
    x: { bg: 'bg-amber-200', rotate: 'rotate-2' },
    tiktok: { bg: 'bg-fuchsia-200', rotate: '-rotate-1' },
    youtube: { bg: 'bg-red-200', rotate: 'rotate-1' },
    pinterest: { bg: 'bg-rose-200', rotate: '-rotate-2' },
    threads: { bg: 'bg-emerald-200', rotate: 'rotate-2' },
    bluesky: { bg: 'bg-cyan-200', rotate: '-rotate-1' },
    mastodon: { bg: 'bg-violet-200', rotate: 'rotate-1' },
    telegram: { bg: 'bg-sky-200', rotate: '-rotate-2' },
    discord: { bg: 'bg-indigo-200', rotate: 'rotate-1' },
};

export const getPlatformLogo = (platform: string): string =>
    PLATFORM_LOGOS[platform] ?? PLATFORM_LOGOS.linkedin;

export const getPlatformTheme = (platform: string): { bg: string; rotate: string; image: string } => ({
    ...(PLATFORM_THEMES[platform] ?? { bg: 'bg-muted', rotate: '' }),
    image: getPlatformLogo(platform),
});

export const getPlatformLabel = (platform: string): string =>
    PLATFORM_LABELS[platform] ?? platform;

export const getContentTypeOptions = (platform: string): ContentTypeOption[] =>
    (PLATFORM_CONTENT_TYPES[platform] ?? []).map((value) => ({
        value,
        labelKey: `posts.content_types.${value}.label`,
    }));
