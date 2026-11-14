import { router } from '@inertiajs/vue3';
import { computed, inject, nextTick, ref } from 'vue';

import { edit as editPostRoute } from '@/actions/App/Http/Controllers/App/PostController';
import {
    copyPostCardLink,
    deletePostCardKey,
    duplicatePostCard,
    editPostCardUrlKey,
    schedulePostCard,
    sharePostCardLink,
    usePageReload,
} from '@/composables/usePostCardActions';
import { filled } from '@/lib/helpers';
import type { PostCard, PostCardMenuAction } from '@/types/publish';

/**
 * What a post card's ⋮ menu and its details dialog do, shared by the List
 * card, the board card and the calendar chip. The details and recurrence
 * dialogs the actions open are rendered by the card; with `lazy`, they mount
 * on first use only (`dialogsMounted`), so a full board or calendar doesn't
 * mount two dialogs per post.
 */
export const usePostCardMenuActions = (
    post: () => PostCard,
    options: {
        /** The zone the recurrence dialog falls back to when the post's channel has none. */
        timezone: () => string;
        detailsOpen?: boolean;
        lazy?: boolean;
    },
) => {
    const deletePost = inject(deletePostCardKey, () => {});
    const editUrl = inject(editPostCardUrlKey, (target: PostCard) =>
        editPostRoute.url(target.id),
    );

    const pageReload = usePageReload();

    const dialogsMounted = ref(!options.lazy);
    const detailsOpen = ref(options.detailsOpen ?? false);
    const recurrenceOpen = ref(false);
    const recurrencePost = ref<PostCard>(post());

    /** The post the recurrence dialog edits: this card's, kept current, or a sibling picked in the details. */
    const recurrenceTarget = computed((): PostCard => {
        if (recurrencePost.value.id === post().id) {
            return post();
        }

        return recurrencePost.value;
    });

    /** The recurrence dialog reads times in the edited post's channel zone. */
    const recurrenceTimezone = computed((): string => {
        const channelZone = recurrenceTarget.value.social_account?.timezone;

        if (filled(channelZone)) {
            return channelZone;
        }

        return options.timezone();
    });

    /** Runs `open` now when the dialogs are mounted, else once they mount. */
    const withDialogs = (open: () => void): void => {
        if (dialogsMounted.value) {
            open();

            return;
        }

        dialogsMounted.value = true;
        void nextTick(open);
    };

    const openDetails = (): void => {
        withDialogs(() => {
            detailsOpen.value = true;
        });
    };

    const openRecurrence = (target: PostCard): void => {
        withDialogs(() => {
            recurrencePost.value = target;
            recurrenceOpen.value = true;
        });
    };

    const edit = (target: PostCard = post()): void => {
        router.visit(editUrl(target));
    };

    const schedule = (
        target: PostCard,
        action: 'publish_now' | 'draft',
    ): void => {
        schedulePostCard(target.id, action, pageReload());
    };

    const publishNow = (): void => {
        schedule(post(), 'publish_now');
    };

    const runPostAction = (action: PostCardMenuAction, target: PostCard): void => {
        switch (action) {
            case 'publish_now':
                schedule(target, 'publish_now');
                break;
            case 'duplicate':
                duplicatePostCard(target);
                break;
            case 'recurrence':
                openRecurrence(target);
                break;
            case 'move_drafts':
                schedule(target, 'draft');
                break;
            case 'details':
                openDetails();
                break;
            case 'share_link':
                sharePostCardLink(target);
                break;
            case 'copy_link':
                copyPostCardLink(target);
                break;
            case 'delete':
                deletePost(target);
                break;
        }
    };

    /** Runs a menu choice on this card's post. */
    const runMenuAction = (action: PostCardMenuAction): void => {
        runPostAction(action, post());
    };

    return {
        dialogsMounted,
        detailsOpen,
        recurrenceOpen,
        recurrenceTarget,
        recurrenceTimezone,
        openDetails,
        edit,
        publishNow,
        runPostAction,
        runMenuAction,
    };
};
