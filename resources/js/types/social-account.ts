import type { MediaType } from '@/lib/mediaType';
import { Platform } from '@/types/platform';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

export interface AvailablePlatform {
    value: string;
    label: string;
    network: string;
    analytics?: boolean;
    text_only?: boolean;
    media_types?: MediaType[];
    connect_methods?: string[];
}

export interface ConnectedAccount {
    id: string;
    platform: string;
    network: string;
    username: string;
    display_name: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
    profile_url?: string | null;
    status: SocialAccountStatusValue | null;
}

export const isConnectionLost = (account: {
    status: SocialAccountStatusValue | null;
}): boolean =>
    account.status === 'disconnected' || account.status === 'token_expired';

export const accountTypeKey = (account: { platform: string }): string | null =>
    account.platform === Platform.LinkedInPage ||
    account.platform === Platform.InstagramFacebook
        ? `channels.variants.${account.platform}`
        : null;
