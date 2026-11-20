import { nextTick, ref, watch } from 'vue';

import { useUnsavedChanges } from '@/composables/composer/useUnsavedChanges';
import type { usePostComposition } from '@/composables/usePostComposition';
import type { InstagramAudio } from '@/types/instagram';
import { PostStatus } from '@/types/post';

interface ComposerUnsavedChangesOptions {
    /** Only an edit of a saved post can lose work: a new post is autosaved. */
    editingSavedPost: boolean;
    isOpen: () => boolean;
    /** Closes the composer: what discarding after the browser's back button does. */
    close: () => void;
    composition: ReturnType<typeof usePostComposition>;
    /** The schedule picked in the composer: now, custom, next or top. */
    scheduleMode: () => string;
    /** A file still uploading: closing would lose it before it joins the post. */
    uploading: () => boolean;
}

/**
 * The meta as the guard compares it: an Instagram track counts by its id and
 * volumes only. Its name, cover and preview come with the id, and the composer
 * fills them in or renews them on its own, so they never count as a change.
 */
const withoutTrackDetails = (meta: Record<string, any>): Record<string, any> => {
    const audio = meta.audio as InstagramAudio | null | undefined;

    if (!audio) return meta;

    return {
        ...meta,
        audio: {
            id: audio.id,
            audio_volume: audio.audio_volume,
            video_volume: audio.video_volume,
        },
    };
};

/**
 * The composer's unsaved-changes guard: compares what saving would send with
 * what it would have sent when the post opened. A file still uploading counts
 * too: it joins the post only once the upload finishes.
 *
 * Settings fill some values in on their own once their data loads (the TikTok
 * creator's limits), so the opened state is taken again at the person's first
 * interaction and nothing counts before it. A music track's name, cover and
 * preview can arrive after that interaction, so they are left out entirely.
 */
export const useComposerUnsavedChanges = (
    options: ComposerUnsavedChangesOptions,
) => {
    const { composition, scheduleMode } = options;

    /** What saving would send, a track's details aside, so any change the person makes counts. */
    const snapshot = (): string => {
        const mode = scheduleMode();
        const submission = composition.materialize(
            PostStatus.Scheduled,
            mode === 'next' || mode === 'top' ? mode : null,
        );

        return JSON.stringify({
            submission: {
                ...submission,
                destinations: submission.destinations.map((destination) => ({
                    ...destination,
                    meta: withoutTrackDetails(destination.meta),
                })),
            },
            scheduleMode: mode,
        });
    };

    const opened = ref<string | null>(null);
    const interacted = ref(false);
    const saved = ref(false);

    watch(
        () =>
            composition.selectedAccounts.value.length ===
            composition.selectedAccountIds.value.length,
        async (ready) => {
            if (!ready || opened.value !== null) return;
            await nextTick();
            opened.value = snapshot();
        },
        { immediate: true },
    );

    const markInteracted = (): void => {
        if (interacted.value) return;
        interacted.value = true;
        if (opened.value !== null) {
            opened.value = snapshot();
        }
    };

    const markSaved = (): void => {
        saved.value = true;
    };

    const hasUnsavedChanges = (): boolean =>
        options.editingSavedPost &&
        options.isOpen() &&
        !saved.value &&
        (options.uploading() ||
            (interacted.value &&
                opened.value !== null &&
                snapshot() !== opened.value));

    return {
        ...useUnsavedChanges(hasUnsavedChanges, options.close),
        markInteracted,
        markSaved,
    };
};
