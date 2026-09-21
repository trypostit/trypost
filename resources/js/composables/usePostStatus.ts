import {
    IconAlertCircle,
    IconBan,
    IconCircleCheck,
    IconClock,
    IconFileText,
    IconHourglass,
    IconLoader2,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

import { PostPlatformStatus, PostStatus } from '@/types/post';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'success' | 'warning' | 'outline';

interface StatusConfig {
    variant: BadgeVariant;
    icon: typeof IconFileText;
    label: string;
}

const CONFIGS: Record<string, Pick<StatusConfig, 'variant' | 'icon'>> = {
    draft: { variant: 'outline', icon: IconFileText },
    scheduled: { variant: 'default', icon: IconClock },
    publishing: { variant: 'warning', icon: IconLoader2 },
    retrying: { variant: 'warning', icon: IconLoader2 },
    published: { variant: 'success', icon: IconCircleCheck },
    partially_published: { variant: 'warning', icon: IconAlertCircle },
    failed: { variant: 'destructive', icon: IconAlertCircle },
    rejected: { variant: 'destructive', icon: IconBan },
    pending_review: { variant: 'warning', icon: IconHourglass },
};

const IN_FLIGHT_PLATFORM_STATUSES: readonly string[] = [
    PostPlatformStatus.Publishing,
    PostPlatformStatus.Pending,
    PostPlatformStatus.Retrying,
];

/**
 * Full-screen publishing overlay only while a target is still in flight.
 * `pending_review` keeps the post status `publishing`, but Google is already
 * holding the Local Post — hide the spinner and show the platform rows.
 */
export const isActivelyPublishing = (
    postStatus: string,
    platforms: { enabled?: boolean; status: string }[],
): boolean => {
    if (postStatus !== PostStatus.Publishing) {
        return false;
    }

    return platforms.some((platform) => platform.enabled !== false
        && IN_FLIGHT_PLATFORM_STATUSES.includes(platform.status));
};

export const getPostStatusConfig = (status: string): StatusConfig => {
    const config = CONFIGS[status] ?? CONFIGS.draft;
    return { ...config, label: trans(`posts.status.${status}`) };
};

export const getPlatformStatusConfig = (status: string): StatusConfig => {
    const map: Record<string, string> = {
        pending: 'draft',
        publishing: 'publishing',
        retrying: 'retrying',
        published: 'published',
        failed: 'failed',
        rejected: 'rejected',
        pending_review: 'pending_review',
    };
    const key = map[status] ?? 'draft';
    const config = CONFIGS[key];
    return { ...config, label: trans(`posts.edit.status.${status}`) };
};
