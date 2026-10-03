import {
    IconAlertCircle,
    IconBan,
    IconCircleCheck,
    IconClock,
    IconEdit,
    IconFileText,
    IconHourglass,
    IconLoader2,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'destructive-subtle' | 'success' | 'warning' | 'info' | 'outline';

interface StatusConfig {
    variant: BadgeVariant;
    icon: typeof IconFileText;
    label: string;
}

const CONFIGS: Record<string, Pick<StatusConfig, 'variant' | 'icon'>> = {
    draft: { variant: 'info', icon: IconEdit },
    pending_approval: { variant: 'warning', icon: IconEdit },
    scheduled: { variant: 'default', icon: IconClock },
    publishing: { variant: 'warning', icon: IconLoader2 },
    retrying: { variant: 'warning', icon: IconLoader2 },
    published: { variant: 'success', icon: IconCircleCheck },
    partially_published: { variant: 'warning', icon: IconAlertCircle },
    failed: { variant: 'destructive-subtle', icon: IconAlertCircle },
    rejected: { variant: 'destructive-subtle', icon: IconBan },
    pending_review: { variant: 'warning', icon: IconHourglass },
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
