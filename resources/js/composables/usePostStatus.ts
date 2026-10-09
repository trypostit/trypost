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
    /** In-flight statuses spin their icon so they never read as stuck. */
    iconClass?: string;
    label: string;
}

const CONFIGS: Record<string, Pick<StatusConfig, 'variant' | 'icon' | 'iconClass'>> = {
    draft: { variant: 'info', icon: IconEdit },
    pending_approval: { variant: 'warning', icon: IconEdit },
    scheduled: { variant: 'default', icon: IconClock },
    publishing: { variant: 'warning', icon: IconLoader2, iconClass: 'animate-spin' },
    retrying: { variant: 'warning', icon: IconLoader2, iconClass: 'animate-spin' },
    published: { variant: 'success', icon: IconCircleCheck },
    failed: { variant: 'destructive-subtle', icon: IconAlertCircle },
    rejected: { variant: 'destructive-subtle', icon: IconBan },
    pending_review: { variant: 'warning', icon: IconHourglass },
};

export const getPostStatusConfig = (status: string): StatusConfig => {
    const config = CONFIGS[status] ?? CONFIGS.draft;
    return { ...config, label: trans(`posts.status.${status}`) };
};
