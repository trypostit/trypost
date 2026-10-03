import { router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import type { InjectionKey, Ref } from 'vue';
import { toast } from 'vue-sonner';

import { duplicate as duplicatePost } from '@/actions/App/Http/Controllers/App/PostController';
import { update as updatePostLabels } from '@/actions/App/Http/Controllers/App/PostLabelController';
import { update as updatePostSchedule } from '@/actions/App/Http/Controllers/App/PostScheduleController';
import type {
    PostCard,
    PostCardLabel,
    PostScheduleAction,
} from '@/types/publish';

export const deletePostCardKey: InjectionKey<(post: PostCard) => void> =
    Symbol('deletePostCard');

export const editPostCardUrlKey: InjectionKey<(post: PostCard) => string> =
    Symbol('editPostCardUrl');

export const postCardLabelsKey: InjectionKey<Readonly<Ref<PostCardLabel[]>>> =
    Symbol('postCardLabels');

const successMessage = (
    action: PostScheduleAction,
    requestsApproval: boolean,
): string | null => {
    if (action !== 'queue_next' && action !== 'queue_top') {
        return null;
    }

    return requestsApproval
        ? trans('posts.approvals.requested')
        : transChoice('posts.composer.queue.added', 1, { count: '1' });
};

export const toastFirstError = (errors: Record<string, string>): void => {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
};

export const schedulePostCard = (
    postId: string,
    action: PostScheduleAction,
    options: {
        only?: string[];
        reset?: string[];
        requestsApproval?: boolean;
    } = {},
): void => {
    const { requestsApproval = false, ...reload } = options;

    router.put(
        updatePostSchedule.url(postId),
        { action },
        {
            ...reload,
            preserveScroll: true,
            onSuccess: () => {
                const message = successMessage(action, requestsApproval);

                if (message) {
                    toast.success(message, { testId: 'post-schedule-toast' });
                }
            },
            onError: toastFirstError,
        },
    );
};

export const duplicatePostCard = (post: PostCard): void => {
    router.post(duplicatePost.url(post.id), {
        post_platform_id: post.post_platforms[0]?.id,
    });
};

export const syncPostCardLabels = (
    postId: string,
    labelIds: string[],
    rollback: () => void,
): void => {
    const fail = (): void => {
        rollback();
        toast.error(trans('posts.publish.actions.edit_labels_failed'), {
            testId: 'post-labels-error-toast',
        });
    };

    router.patch(
        updatePostLabels.url(postId),
        { labels: labelIds },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['posts', 'queue'],
            reset: ['posts'],
            onError: fail,
            onHttpException: () => {
                fail();

                return false;
            },
        },
    );
};
