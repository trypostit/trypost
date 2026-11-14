import { router, usePage } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { inject, type InjectionKey, type Ref } from 'vue';
import { toast } from 'vue-sonner';

import { duplicate as duplicatePost } from '@/actions/App/Http/Controllers/App/PostController';
import { update as updatePostLabels } from '@/actions/App/Http/Controllers/App/PostLabelController';
import { update as updatePostSchedule } from '@/actions/App/Http/Controllers/App/PostScheduleController';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { filled } from '@/lib/helpers';
import { postPermalink } from '@/lib/postNetwork';
import { copyToClipboard } from '@/lib/utils';
import type { SidebarChannel } from '@/types/channel';
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

export interface PostCardReload {
    only: string[];
    reset: string[];
}

/** What a List card's approval action (approve, reject, take back) reloads. */
export const POST_CARD_APPROVAL_RELOAD: PostCardReload = {
    only: ['posts', 'counts', 'queue', 'hasData'],
    reset: ['posts'],
};

/** What a List card's label change reloads. */
export const POST_CARD_LABELS_RELOAD: PostCardReload = {
    only: ['posts', 'queue'],
    reset: ['posts'],
};

/** The props a card action reloads, when the page lists its posts under other props than `posts`. */
export const postCardReloadKey: InjectionKey<() => PostCardReload> =
    Symbol('postCardReload');

/** The reload a page asks its card actions for (the board's columns); none on the List, which does a full visit. */
export const usePageReload = (): (() => Partial<PostCardReload>) => {
    const pageReload = inject(postCardReloadKey, null);

    return () => {
        if (pageReload === null) {
            return {};
        }

        return pageReload();
    };
};

/** The reload a card action sends: the page's own when it provides one, else the List's. */
export const usePostCardReload = (
    fallback: PostCardReload,
): (() => PostCardReload) => inject(postCardReloadKey, () => fallback);

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
        onStart?: () => void;
        onFinish?: () => void;
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
    router.post(duplicatePost.url(post.id));
};

/** Opens the composer with the sent post's link and every channel of the workspace. */
export const sharePostCardLink = (post: PostCard): void => {
    const permalink = postPermalink(post);

    if (!filled(permalink)) return;

    const channels = (usePage().props.channels as SidebarChannel[] | undefined) ?? [];

    openPostComposer({
        socialAccountIds: channels.map((channel) => channel.id),
        draft: {
            content: permalink,
            media: [],
            scheduled_at: null,
            label_ids: [],
        },
    });
};

export const copyPostCardLink = (post: PostCard): void => {
    const permalink = postPermalink(post);

    if (filled(permalink)) {
        void copyToClipboard(permalink, undefined, {
            showSuccessToast: false,
        });
    }
};

export const syncPostCardLabels = (
    postId: string,
    labelIds: string[],
    rollback: () => void,
    reload: PostCardReload = POST_CARD_LABELS_RELOAD,
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
            ...reload,
            onError: fail,
            onHttpException: () => {
                fail();

                return false;
            },
        },
    );
};
