import { PostStatus, PublishStatus } from '@/types/post';
import type { PostCard } from '@/types/publish';

/** Whether the network deleted the post after it was published. */
export const isDeletedOnNetwork = (post: PostCard): boolean =>
    post.status === PostStatus.Published &&
    post.publish_status === PublishStatus.Deleted;

/** The post's link on the network, none once the network deleted it. */
export const postPermalink = (post: PostCard): string | null =>
    isDeletedOnNetwork(post) ? null : (post.platform_url ?? null);
