import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { filled } from '@/lib/helpers';
import type { BadgeVariant } from '@/types/admin';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

/** How the admin shows where a channel's connection stands. */
const CHANNEL_STATUSES: Record<SocialAccountStatusValue, { label: string; variant: BadgeVariant }> = {
    connected: { label: 'Connected', variant: 'success' },
    token_expired: { label: 'Needs reconnect', variant: 'warning' },
    disconnected: { label: 'Disconnected', variant: 'destructive' },
};

/** Statuses a channel must be reconnected from before a post on it can be retried. */
const RECONNECT_STATUSES: SocialAccountStatusValue[] = ['token_expired', 'disconnected'];

export const channelStatus = (status: SocialAccountStatusValue): { label: string; variant: BadgeVariant } =>
    CHANNEL_STATUSES[status];

export const needsReconnect = (status: SocialAccountStatusValue): boolean => RECONNECT_STATUSES.includes(status);

/** A channel's name, or its network's when the channel has none. */
export const channelDisplayName = (name: string | null, platform: string): string => {
    if (filled(name)) {
        return name;
    }

    return getPlatformLabel(platform);
};

/** Where a channel's connection stands; a channel that was deleted has no status. */
export const channelStatusLabel = (status: SocialAccountStatusValue | null): string => {
    if (filled(status)) {
        return channelStatus(status).label;
    }

    return 'Channel gone';
};
