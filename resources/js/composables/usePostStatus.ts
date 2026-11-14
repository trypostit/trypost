import {
    IconAlertCircle,
    IconCircleCheck,
    IconClock,
    IconEdit,
    IconFileText,
    IconHourglass,
    IconLoader2,
    IconTrashX,
} from '@tabler/icons-vue';

import { isDeletedOnNetwork } from '@/lib/postNetwork';
import { PostStatus, PublishStatus, type PostStatusValue } from '@/types/post';
import type { PostCard } from '@/types/publish';

/**
 * The status a post's badge shows: its lifecycle status, or what the network
 * says about it once that matters more (in Google's review, deleted on the
 * network). Its label is always `posts.status.<status>`.
 */
export type PostDisplayStatus =
    | PostStatusValue
    | 'retrying'
    | 'pending_review'
    | 'deleted_on_network';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'destructive-subtle' | 'success' | 'warning' | 'info' | 'outline';

interface StatusConfig {
    variant: BadgeVariant;
    icon: typeof IconFileText;
    /** In-flight statuses spin their icon so they never read as stuck. */
    iconClass?: string;
    /** The label's lang key, for templates to resolve with `$t`. */
    labelKey: string;
}

const CONFIGS: Record<PostDisplayStatus, Omit<StatusConfig, 'labelKey'>> = {
    draft: { variant: 'info', icon: IconEdit },
    pending_approval: { variant: 'secondary', icon: IconHourglass },
    scheduled: { variant: 'default', icon: IconClock },
    publishing: { variant: 'warning', icon: IconLoader2, iconClass: 'animate-spin' },
    retrying: { variant: 'warning', icon: IconLoader2, iconClass: 'animate-spin' },
    published: { variant: 'success', icon: IconCircleCheck },
    failed: { variant: 'destructive-subtle', icon: IconAlertCircle },
    pending_review: { variant: 'warning', icon: IconHourglass },
    deleted_on_network: { variant: 'destructive-subtle', icon: IconTrashX },
};

/** How a status's badge looks and which lang key names it. */
export const getPostStatusConfig = (status: PostDisplayStatus): StatusConfig => ({
    ...CONFIGS[status],
    labelKey: `posts.status.${status}`,
});

/** What a post's badge shows: deleted on the network, in Google's review, else its status. */
export const postDisplayStatus = (post: PostCard): PostDisplayStatus => {
    if (isDeletedOnNetwork(post)) {
        return 'deleted_on_network';
    }

    if (post.status === PostStatus.Publishing && post.publish_status === PublishStatus.PendingReview) {
        return 'pending_review';
    }

    return post.status;
};
