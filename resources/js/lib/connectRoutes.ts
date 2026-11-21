/**
 * Where each network's connect flow starts, and the URL that reconnects a
 * channel through it (no Vue or Inertia here, so it runs anywhere).
 */
import { connect as blueskyConnect } from '@/routes/app/social/bluesky';
import { connect as discordConnect } from '@/routes/app/social/discord';
import { connect as facebookConnect } from '@/routes/app/social/facebook';
import { connect as googleBusinessConnect } from '@/routes/app/social/google-business';
import { connect as instagramConnect } from '@/routes/app/social/instagram';
import { connect as instagramFacebookConnect } from '@/routes/app/social/instagram-facebook';
import { connect as linkedinConnect } from '@/routes/app/social/linkedin';
import { connect as mastodonConnect } from '@/routes/app/social/mastodon';
import { connect as pinterestConnect } from '@/routes/app/social/pinterest';
import { connect as threadsConnect } from '@/routes/app/social/threads';
import { connect as tiktokConnect } from '@/routes/app/social/tiktok';
import { connect as xConnect } from '@/routes/app/social/x';
import { connect as youtubeConnect } from '@/routes/app/social/youtube';
import { Platform } from '@/types/platform';

const CONNECT_ROUTES: Record<
    string,
    { url: (options?: { query?: Record<string, string> }) => string }
> = {
    [Platform.Bluesky]: blueskyConnect,
    [Platform.Discord]: discordConnect,
    [Platform.Facebook]: facebookConnect,
    [Platform.GoogleBusiness]: googleBusinessConnect,
    [Platform.Instagram]: instagramConnect,
    [Platform.InstagramFacebook]: instagramFacebookConnect,
    [Platform.LinkedIn]: linkedinConnect,
    [Platform.Mastodon]: mastodonConnect,
    [Platform.Pinterest]: pinterestConnect,
    [Platform.Threads]: threadsConnect,
    [Platform.TikTok]: tiktokConnect,
    [Platform.X]: xConnect,
    [Platform.YouTube]: youtubeConnect,
};

/** The connect flow a platform starts from: a LinkedIn Page connects through LinkedIn. */
export const connectEntry = (platform: string): string => {
    if (platform === Platform.LinkedInPage) {
        return Platform.LinkedIn;
    }

    return platform;
};

export const connectUrl = (
    platform: string,
    reconnectId?: string,
): string | undefined =>
    CONNECT_ROUTES[connectEntry(platform)]?.url({
        query: {
            return_to: window.location.pathname,
            ...(reconnectId ? { reconnect: reconnectId } : {}),
        },
    });
