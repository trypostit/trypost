import type { MediaType } from '@/lib/mediaType';
import { Platform } from '@/types/platform';
import type { TimezoneOption } from '@/types/posting-schedule';
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

/** Where a channel stands on its weekly posting goal this week; no goal is null. */
export interface PostingGoalProgress {
    posting_goal: number | null;
    sent_this_week: number;
    scheduled_this_week: number;
}

/** A connected channel as the channels settings list shows it. */
export interface ChannelListRow extends ConnectedAccount, PostingGoalProgress {
    timezone_option: TimezoneOption;
    posting_times_per_week: number;
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
    verified_badge?: VerifiedBadge | null;
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

/** The verification badge a network shows next to an account (only X reports one today). */
export type VerifiedBadge = 'blue' | 'business' | 'government';
