import type { BadgeVariant } from '@/types/admin';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

/** How the backoffice shows where a channel's connection stands. */
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
