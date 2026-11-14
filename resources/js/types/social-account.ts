import { filled } from '@/lib/helpers';
import type { MediaType } from '@/lib/mediaType';
import { Platform } from '@/types/platform';
import type { TimezoneOption } from '@/types/posting-schedule';
import {
    SocialAccountStatus,
    type SocialAccountStatusValue,
} from '@/types/social-account-status';

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

const CONNECTION_LOST_STATUSES: SocialAccountStatusValue[] = [
    SocialAccountStatus.Disconnected,
    SocialAccountStatus.TokenExpired,
];

/** Networks connected in several ways, whose accounts name the way they were connected ("LinkedIn Page"). */
const PLATFORMS_WITH_ACCOUNT_TYPE: string[] = [
    Platform.LinkedInPage,
    Platform.InstagramFacebook,
];

/** A channel that can no longer publish until it is reconnected. */
export const isConnectionLost = (account: {
    status: SocialAccountStatusValue | null;
}): boolean => {
    if (!filled(account.status)) {
        return false;
    }

    return CONNECTION_LOST_STATUSES.includes(account.status);
};

/** The lang key of an account's type ("LinkedIn Page"); none on a network connected one way only. */
export const accountTypeKey = (account: { platform: string }): string | null => {
    if (!PLATFORMS_WITH_ACCOUNT_TYPE.includes(account.platform)) {
        return null;
    }

    return `channels.variants.${account.platform}`;
};

/** The verification badge a network shows next to an account (only X reports one today). */
export type VerifiedBadge = 'blue' | 'business' | 'government';
