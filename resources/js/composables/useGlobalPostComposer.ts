import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';

import type { ComposerInitialDraft } from '@/composables/usePostComposition';

export interface PostComposerRequest {
    id: number;
    date: string | null;
    assistant: boolean;
    socialAccountIds: string[];
    draft: ComposerInitialDraft | null;
    queueSlot: string | null;
}

let nextRequestId = 0;

export const postComposerRequest = shallowRef<PostComposerRequest | null>(null);

const openComposerDialogs = ref(0);

/**
 * Whether a post composer is on screen, however it opened: a request still
 * loading its data, or any composer dialog (the global one, or the edit and
 * create dialogs of the publish page).
 */
export const isPostComposerOpen = computed(
    () => postComposerRequest.value !== null || openComposerDialogs.value > 0,
);

export const trackPostComposerOpen = (isOpen: () => boolean): void => {
    let counted = false;

    const sync = (open: boolean): void => {
        if (open === counted) return;

        counted = open;
        openComposerDialogs.value += open ? 1 : -1;
    };

    watch(isOpen, sync, { immediate: true });
    onBeforeUnmount(() => sync(false));
};

export const openPostComposer = (
    options: {
        date?: string | null;
        assistant?: boolean;
        socialAccountIds?: string[];
        draft?: ComposerInitialDraft | null;
        queueSlot?: string | null;
    } = {},
): void => {
    if (typeof window === 'undefined') return;

    postComposerRequest.value = {
        id: ++nextRequestId,
        date: options.date ?? null,
        assistant: options.assistant ?? false,
        socialAccountIds: options.socialAccountIds ?? [],
        draft: options.draft ?? null,
        queueSlot: options.queueSlot ?? null,
    };
};

export const closePostComposer = (): void => {
    postComposerRequest.value = null;
};
