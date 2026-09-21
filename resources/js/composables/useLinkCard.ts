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

const firstHttpUrl = (text: string): string | null =>
    text.match(/https?:\/\/\S+/)?.[0] ?? null;

/**
 * OpenGraph card for the link a platform will publish. Attached media hides it.
 * The default is the first http(s) URL; pass a picker when the platform skips
 * some hosts. The backend trims the URL and returns the card.
 */
export const useLinkCard = (
    content: Ref<string>,
    media: Ref<MediaItem[]>,
    selectUrl: (text: string) => string | null = firstHttpUrl,
) => {
    const card = ref<LinkCard | null>(null);
    const loading = ref(false);
    const http = useHttp<{ url: string }, LinkCard | null>({ url: '' });

    const url = computed(() =>
        media.value.length === 0 ? selectUrl(content.value) : null,
    );

    const loadCard = async (target: string): Promise<void> => {
        loading.value = true;
        http.url = target;

        const data = await http.post(linkPreview.url()).catch(() => null);

        // A slow response must not revive a removed link or overwrite a newer one.
        if (url.value !== target) {
            return;
        }

        card.value = data?.uri ? data : null;
        loading.value = false;
    };

    watch(url, (next) => {
        if (next) {
            return;
        }

        card.value = null;
        loading.value = false;
    });

    watchDebounced(
        url,
        (next) => {
            if (next) {
                void loadCard(next);
            }
        },
        { debounce: 400, immediate: true },
    );

    return { card, loading };
};
