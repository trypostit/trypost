import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import type { SavedReply } from '@/lib/savedReplies';

/**
 * Saved replies created from a picker since the page loaded. The page's
 * `savedReplies` once prop only comes back on the next visit, so these keep
 * a reply created in the inbox available in every picker meanwhile.
 */
const createdHere = ref<SavedReply[]>([]);

export const useSavedReplies = () => {
    const page = usePage();

    const savedReplies = computed<SavedReply[]>(() => {
        const fromPage = (page.props.savedReplies as SavedReply[] | undefined) ?? [];
        const known = new Set(fromPage.map((savedReply) => savedReply.id));

        return [
            ...createdHere.value.filter((savedReply) => !known.has(savedReply.id)),
            ...fromPage,
        ];
    });

    const remember = (savedReply: SavedReply): void => {
        createdHere.value = [
            savedReply,
            ...createdHere.value.filter((existing) => existing.id !== savedReply.id),
        ];
    };

    return { savedReplies, remember };
};
