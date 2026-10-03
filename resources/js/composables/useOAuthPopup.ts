import { trans } from 'laravel-vue-i18n';
import { onMounted, onUnmounted } from 'vue';
import { toast } from 'vue-sonner';

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

const POPUP_WIDTH = 600;
const POPUP_HEIGHT = 700;

const CONNECT_ROUTES: Record<string, { url: (options?: { query?: Record<string, string> }) => string }> = {
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

export interface SocialOAuthResult {
    success: boolean;
    message: string;
    platform: string | null;
    accountId: string | null;
    created: boolean;
}

export const oauthConnectUrl = (platform: string, reconnectId?: string): string | undefined => {
    const route = CONNECT_ROUTES[platform];

    if (!route) {
        return undefined;
    }

    return route.url(reconnectId ? { query: { reconnect: reconnectId } } : undefined);
};

export const openOAuthPopupWindow = (url: string): void => {
    const left = window.screenX + (window.outerWidth - POPUP_WIDTH) / 2;
    const top = window.screenY + (window.outerHeight - POPUP_HEIGHT) / 2;

    const popup = window.open(
        url,
        'oauth-popup',
        `width=${POPUP_WIDTH},height=${POPUP_HEIGHT},left=${left},top=${top},scrollbars=yes,resizable=yes`,
    );

    if (!popup) {
        toast.error(trans('accounts.popup_callback.popup_blocked'));
    }
};

/**
 * Listens for the popup's `{success, message}` postback and invokes
 * `onResult`. Call it exactly once per page, from the globally mounted
 * ConnectChannelDialog; other callers open popups with
 * `openOAuthPopupWindow`.
 */
export const useOAuthPopup = (onResult: (result: SocialOAuthResult) => void) => {
    const handleMessage = (event: MessageEvent) => {
        if (event.origin !== window.location.origin) return;
        if (event.data?.type !== 'social-oauth-callback') return;

        onResult({
            success: Boolean(event.data.success),
            message: String(event.data.message ?? ''),
            platform: event.data.platform ?? null,
            accountId: event.data.account_id ?? null,
            created: Boolean(event.data.created),
        });
    };

    onMounted(() => window.addEventListener('message', handleMessage));
    onUnmounted(() => window.removeEventListener('message', handleMessage));
};
