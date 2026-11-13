import {
    IconAlertCircle,
    IconBan,
    IconCircleCheck,
    IconClock,
    IconEdit,
    IconFileText,
    IconHourglass,
    IconLoader2,
    IconTrashX,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

import { isDeletedOnNetwork } from '@/lib/postNetwork';
import { PostStatus } from '@/types/post';
import type { PostCard, PublishTab } from '@/types/publish';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'destructive-subtle' | 'success' | 'warning' | 'info' | 'outline';

interface StatusConfig {
    variant: BadgeVariant;
    icon: typeof IconFileText;
    /** In-flight statuses spin their icon so they never read as stuck. */
    iconClass?: string;
    label: string;
    /** The label's lang key, for templates to resolve with `$t`. */
    labelKey: string;
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
    const labelKey = `posts.status.${status}`;

    return { ...config, label: trans(labelKey), labelKey };
};

export const getDeletedOnNetworkStatusConfig = (): StatusConfig => ({
    variant: 'destructive-subtle',
    icon: IconTrashX,
    label: trans('posts.publish.deleted_on_network.badge'),
    labelKey: 'posts.publish.deleted_on_network.badge',
});

/** A post's status, reading as deleted once the network deleted it. */
export const getPostCardStatusConfig = (post: PostCard): StatusConfig =>
    isDeletedOnNetwork(post)
        ? getDeletedOnNetworkStatusConfig()
        : getPostStatusConfig(post.status);

/** The Publish tab a post belongs to, so its details open as they do there. */
export const publishTabFor = (post: PostCard): PublishTab => {
    if (post.status === PostStatus.Published || post.status === PostStatus.Failed) {
        return 'sent';
    }

    if (post.status === PostStatus.Draft) {
        return 'drafts';
    }

    return post.status === PostStatus.PendingApproval ? 'approvals' : 'queue';
};
