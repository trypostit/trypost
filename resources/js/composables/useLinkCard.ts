import { useHttp } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch, type Ref } from 'vue';

import { linkPreview } from '@/routes/app/posts';
import type { MediaItem } from '@/types/media';

export interface LinkCard {
    uri: string;
    domain: string;
    title: string;
    description: string;
    image: string | null;
}

/**
 * Live link-preview card for the composer. Detects the first link in `content`
 * — a rough match is enough, because the backend re-detects it and returns the
 * exact, trimmed URL as `card.uri` — and resolves its OpenGraph card, but only
 * when no media is attached (media suppresses the link card on every platform).
 */
export const useLinkCard = (content: Ref<string>, media: Ref<MediaItem[]>) => {
    const card = ref<LinkCard | null>(null);
    const loading = ref(false);
    const http = useHttp<{ url: string }, LinkCard | null>({ url: '' });

    const url = computed(() =>
        media.value.length > 0 ? null : (content.value.match(/https?:\/\/\S+/)?.[0] ?? null),
    );

    const fetchCard = async (target: string): Promise<void> => {
        loading.value = true;

        try {
            http.url = target;
            const data = await http.post(linkPreview.url());
            card.value = data?.uri ? data : null;
        } catch {
            card.value = null;
        } finally {
            loading.value = false;
        }
    };

    // The link went away (media attached, or URL removed) — drop the card at once.
    watch(url, (next) => {
        if (!next) {
            card.value = null;
            loading.value = false;
        }
    });

    // A new link appeared — fetch it. `watch` only fires on change, so the same
    // URL is never re-requested; the debounce keeps it off every keystroke.
    watchDebounced(
        url,
        (next) => {
            if (next) {
                void fetchCard(next);
            }
        },
        { debounce: 400, immediate: true },
    );

    return { card, loading };
};
