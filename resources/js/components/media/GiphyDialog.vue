<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconLoader2, IconSearch } from '@tabler/icons-vue';
import Giphy from '@thesvg/vue/giphy';
import { useIntersectionObserver } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, useTemplateRef, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    type MediaImportStarted,
    startMediaImport,
} from '@/composables/useMediaImport';
import debounce from '@/debounce';
import { blank, filled, transform } from '@/lib/helpers';
import { search, trending } from '@/routes/app/media/giphy';

interface GiphyGif {
    id: string;
    title: string | null;
    url_preview: string;
    url: string;
    width: number;
    height: number;
    giphy_url: string | null;
}

interface GiphyPage {
    results: GiphyGif[];
    has_more: boolean;
}

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    (event: 'import-started', started: MediaImportStarted): void;
}>();

const SEARCH_DEBOUNCE_MS = 400;

const query = ref('');
const gifs = ref<GiphyGif[]>([]);
const page = ref(1);
const hasMore = ref(false);
const loading = ref(false);
const failed = ref(false);
const savingId = ref<string | null>(null);
const scroller = useTemplateRef<HTMLElement>('scroller');
const sentinel = useTemplateRef<HTMLElement>('sentinel');
let latestRequest = 0;

const browseHttp = useHttp<Record<string, never>, GiphyPage>({});

const hasGifs = computed((): boolean => filled(gifs.value));

const showsEmpty = computed((): boolean => {
    if (loading.value || failed.value) {
        return false;
    }

    return blank(gifs.value);
});

const showsLoadMore = computed((): boolean => {
    if (loading.value || failed.value) {
        return false;
    }

    return hasMore.value;
});

const isPicking = computed((): boolean => filled(savingId.value));

/** The page URL to read: a search while there is a term, else trending. */
const pageUrl = (term: string, nextPage: number): string => {
    if (blank(term)) {
        return trending.url({ query: { page: String(nextPage) } });
    }

    return search.url({ query: { query: term, page: String(nextPage) } });
};

/** The GIFs already shown, plus the new page without the ones GIPHY repeats. */
const mergedGifs = (results: GiphyGif[], nextPage: number): GiphyGif[] => {
    if (nextPage === 1) {
        return results;
    }

    const known = new Set(gifs.value.map((gif) => gif.id));

    return [...gifs.value, ...results.filter((gif) => !known.has(gif.id))];
};

const load = async (nextPage: number): Promise<void> => {
    const request = ++latestRequest;
    loading.value = true;
    failed.value = false;

    try {
        const response = await browseHttp.get(
            pageUrl(query.value.trim(), nextPage),
        );

        if (request !== latestRequest) {
            return;
        }

        gifs.value = mergedGifs(
            transform(response, (found) => found.results, []),
            nextPage,
        );
        page.value = nextPage;
        hasMore.value = transform(response, (found) => found.has_more, false);
    } catch {
        if (request !== latestRequest) {
            return;
        }

        if (nextPage === 1) {
            gifs.value = [];
        }

        failed.value = true;
    } finally {
        if (request === latestRequest) {
            loading.value = false;
        }
    }
};

const loadMore = (): void => {
    if (!showsLoadMore.value) {
        return;
    }

    void load(page.value + 1);
};

useIntersectionObserver(
    sentinel,
    ([entry]) => {
        if (entry?.isIntersecting) {
            loadMore();
        }
    },
    { root: scroller, rootMargin: '200px' },
);

const searchDebounced = debounce(() => {
    void load(1);
}, SEARCH_DEBOUNCE_MS);

const onSearchInput = (): void => {
    searchDebounced();
};

watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            searchDebounced.cancel();

            return;
        }

        query.value = '';
        gifs.value = [];
        hasMore.value = false;
        failed.value = false;
        savingId.value = null;
        void load(1);
    },
    { immediate: true },
);

/** The GIF's own size, so the grid keeps its shape while it loads. */
const gifAspectRatio = (gif: GiphyGif): string | undefined => {
    if (gif.width === 0 || gif.height === 0) {
        return undefined;
    }

    return `${gif.width} / ${gif.height}`;
};

/** What a screen reader says for a GIF: its title, else the picker's name. */
const gifLabel = (gif: GiphyGif, t: (key: string) => string): string => {
    if (filled(gif.title)) {
        return gif.title;
    }

    return t('posts.composer.giphy.title');
};

const isSaving = (gif: GiphyGif): boolean => savingId.value === gif.id;

/** Starts the GIF's import and hands it to the post, which shows it loading. */
const pick = async (gif: GiphyGif): Promise<void> => {
    if (isPicking.value) {
        return;
    }

    savingId.value = gif.id;

    try {
        const importIds = await startMediaImport({
            source: 'giphy',
            url: gif.url,
            gif_id: gif.id,
            giphy_url: gif.giphy_url,
        });

        if (blank(importIds)) {
            return;
        }

        importIds.forEach((importId) =>
            emit('import-started', {
                importId,
                label: trans('posts.composer.giphy.title'),
            }),
        );
        open.value = false;
    } finally {
        savingId.value = null;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[85vh] flex-col gap-4 sm:max-w-3xl"
            data-testid="giphy-dialog"
            :aria-describedby="undefined"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Giphy class="size-5" aria-hidden="true" />
                    {{ $t('posts.composer.giphy.title') }}
                </DialogTitle>
            </DialogHeader>
            <div class="relative">
                <IconSearch
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    type="search"
                    class="ps-9"
                    data-testid="giphy-search"
                    :placeholder="$t('posts.composer.giphy.search_placeholder')"
                    :aria-label="$t('posts.composer.giphy.search_placeholder')"
                    @update:model-value="onSearchInput"
                />
            </div>
            <div
                ref="scroller"
                class="-mx-1 min-h-48 overflow-y-auto px-1"
                :aria-busy="loading"
            >
                <div
                    v-if="hasGifs"
                    class="columns-2 gap-3 sm:columns-3"
                    data-testid="giphy-grid"
                >
                    <button
                        v-for="(gif, i) in gifs"
                        :key="gif.id"
                        type="button"
                        :data-testid="`giphy-gif-${i}`"
                        :disabled="isPicking"
                        :aria-label="gifLabel(gif, $t)"
                        class="relative mb-3 block w-full break-inside-avoid overflow-hidden rounded-lg bg-muted transition-control hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:cursor-wait"
                        :style="{ aspectRatio: gifAspectRatio(gif) }"
                        @click="pick(gif)"
                    >
                        <img
                            :src="gif.url_preview"
                            alt=""
                            class="size-full object-cover"
                            loading="lazy"
                        />
                        <span
                            v-if="isSaving(gif)"
                            class="absolute inset-0 flex items-center justify-center bg-background/60"
                        >
                            <IconLoader2 class="size-5 animate-spin" />
                        </span>
                    </button>
                </div>
                <p
                    v-if="failed"
                    role="alert"
                    class="py-6 text-center text-sm text-destructive"
                    data-testid="giphy-error"
                >
                    {{ $t('posts.composer.giphy.error') }}
                </p>
                <p
                    v-if="showsEmpty"
                    class="py-12 text-center text-sm text-muted-foreground"
                    data-testid="giphy-empty"
                >
                    {{ $t('posts.composer.giphy.empty') }}
                </p>
                <div v-if="loading" class="flex justify-center py-6">
                    <IconLoader2
                        class="size-5 animate-spin text-muted-foreground"
                        aria-hidden="true"
                    />
                </div>
                <div
                    v-if="showsLoadMore"
                    class="flex flex-col items-center pt-1 pb-2"
                >
                    <div ref="sentinel" class="h-px w-full" />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-testid="giphy-load-more"
                        @click="loadMore"
                    >
                        {{ $t('posts.composer.giphy.load_more') }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
