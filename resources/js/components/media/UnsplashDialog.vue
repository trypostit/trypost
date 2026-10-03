<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconLoader2, IconSearch } from '@tabler/icons-vue';
import { useIntersectionObserver } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import debounce from '@/debounce';
import { extractErrorMessage } from '@/lib/httpError';
import { storeFromUrl } from '@/routes/app/media';
import { search, trending } from '@/routes/app/media/unsplash';
import type { MediaItem, MediaSource, SourceMetaValue } from '@/types/media';

interface UnsplashPhoto {
    id: string;
    url_small: string;
    url_regular: string;
    download_location: string;
    description: string | null;
    width: number;
    height: number;
    author: { name: string; url: string | null };
    unsplash_url: string;
}

interface UnsplashPage {
    results: UnsplashPhoto[];
    total_pages?: number;
}

type ImportedMedia = MediaItem & {
    meta?: MediaItem['meta'] & {
        source?: MediaSource;
        source_meta?: Record<string, SourceMetaValue>;
    };
};

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    (event: 'picked', item: MediaItem): void;
}>();

const SEARCH_DEBOUNCE_MS = 400;

const query = ref('');
const photos = ref<UnsplashPhoto[]>([]);
const page = ref(1);
const hasMore = ref(false);
const loading = ref(false);
const failed = ref(false);
const savingId = ref<string | null>(null);
const scroller = useTemplateRef<HTMLElement>('scroller');
const sentinel = useTemplateRef<HTMLElement>('sentinel');
let latestRequest = 0;

const browseHttp = useHttp<Record<string, never>, UnsplashPage>({});
const pickHttp = useHttp<
    {
        url: string;
        filename: string;
        download_location: string;
        photo_id: string;
        author_name: string;
        author_url: string | null;
    },
    ImportedMedia
>({
    url: '',
    filename: '',
    download_location: '',
    photo_id: '',
    author_name: '',
    author_url: null,
});

const load = async (nextPage: number): Promise<void> => {
    const request = ++latestRequest;
    const term = query.value.trim();
    loading.value = true;
    failed.value = false;
    try {
        const response = await browseHttp.get(
            term
                ? search.url({ query: { query: term, page: String(nextPage) } })
                : trending.url({ query: { page: String(nextPage) } }),
        );
        if (request !== latestRequest) return;
        const results = response?.results ?? [];
        const known = new Set(
            nextPage === 1 ? [] : photos.value.map((photo) => photo.id),
        );
        photos.value = [
            ...(nextPage === 1 ? [] : photos.value),
            ...results.filter((photo) => !known.has(photo.id)),
        ];
        page.value = nextPage;
        hasMore.value = term
            ? nextPage < (response?.total_pages ?? 0)
            : results.length > 0;
    } catch {
        if (request !== latestRequest) return;
        if (nextPage === 1) photos.value = [];
        failed.value = true;
    } finally {
        if (request === latestRequest) loading.value = false;
    }
};

const loadMore = (): void => {
    if (!hasMore.value || loading.value || failed.value) return;
    void load(page.value + 1);
};

useIntersectionObserver(
    sentinel,
    ([entry]) => {
        if (entry?.isIntersecting) loadMore();
    },
    { root: scroller, rootMargin: '200px' },
);

const searchDebounced = debounce(() => {
    void load(1);
}, SEARCH_DEBOUNCE_MS);

watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            searchDebounced.cancel();
            return;
        }
        query.value = '';
        photos.value = [];
        hasMore.value = false;
        failed.value = false;
        savingId.value = null;
        void load(1);
    },
    { immediate: true },
);

const pick = async (photo: UnsplashPhoto): Promise<void> => {
    if (savingId.value) return;
    savingId.value = photo.id;
    pickHttp.url = photo.url_regular;
    pickHttp.filename = `unsplash-${photo.id}.jpg`;
    pickHttp.download_location = photo.download_location;
    pickHttp.photo_id = photo.id;
    pickHttp.author_name = photo.author.name;
    pickHttp.author_url = photo.author.url;
    try {
        const media = await pickHttp.post(storeFromUrl.url());
        emit('picked', {
            ...media,
            source: media.meta?.source,
            source_meta: media.meta?.source_meta,
        });
        open.value = false;
    } catch (exception) {
        toast.error(
            extractErrorMessage(exception) ??
                trans('posts.composer.media_sources.errors.import_failed'),
        );
    } finally {
        savingId.value = null;
    }
};

const AUTHOR_SLOT = '%%author%%';
const UNSPLASH_SLOT = '%%unsplash%%';

/** Splits the translated credit around its author and Unsplash links. */
const creditParts = (
    text: string,
): { kind: 'text' | 'author' | 'unsplash'; value: string }[] =>
    text
        .split(new RegExp(`(${AUTHOR_SLOT}|${UNSPLASH_SLOT})`))
        .filter((part) => part !== '')
        .map((part) => ({
            kind:
                part === AUTHOR_SLOT
                    ? 'author'
                    : part === UNSPLASH_SLOT
                      ? 'unsplash'
                      : 'text',
            value: part,
        }));
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[85vh] flex-col gap-4 sm:max-w-3xl"
            data-testid="unsplash-dialog"
            :aria-describedby="undefined"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('posts.composer.unsplash.title')
                }}</DialogTitle>
            </DialogHeader>
            <div class="relative">
                <IconSearch
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    type="search"
                    class="ps-9"
                    data-testid="unsplash-search"
                    :placeholder="
                        $t('posts.composer.unsplash.search_placeholder')
                    "
                    :aria-label="
                        $t('posts.composer.unsplash.search_placeholder')
                    "
                    @update:model-value="searchDebounced()"
                />
            </div>
            <div
                ref="scroller"
                class="-mx-1 min-h-48 overflow-y-auto px-1"
                :aria-busy="loading"
            >
                <div
                    v-if="photos.length"
                    class="columns-2 gap-3 sm:columns-3"
                    data-testid="unsplash-grid"
                >
                    <figure
                        v-for="(photo, i) in photos"
                        :key="photo.id"
                        class="mb-3 break-inside-avoid"
                    >
                        <button
                            type="button"
                            :data-testid="`unsplash-photo-${i}`"
                            :disabled="savingId !== null"
                            :aria-label="photo.description ?? photo.author.name"
                            class="relative block w-full overflow-hidden rounded-lg bg-muted transition-control hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:cursor-wait"
                            :style="{
                                aspectRatio:
                                    photo.width && photo.height
                                        ? `${photo.width} / ${photo.height}`
                                        : undefined,
                            }"
                            @click="pick(photo)"
                        >
                            <img
                                :src="photo.url_small"
                                :alt="photo.description ?? ''"
                                class="size-full object-cover"
                                loading="lazy"
                            />
                            <span
                                v-if="savingId === photo.id"
                                class="absolute inset-0 flex items-center justify-center bg-background/60"
                            >
                                <IconLoader2 class="size-5 animate-spin" />
                            </span>
                        </button>
                        <figcaption
                            class="mt-1 truncate text-xs text-muted-foreground"
                        >
                            <template
                                v-for="(part, partIndex) in creditParts(
                                    $t('posts.composer.unsplash.credit', {
                                        author: AUTHOR_SLOT,
                                        unsplash: UNSPLASH_SLOT,
                                    }),
                                )"
                                :key="partIndex"
                            >
                                <span
                                    v-if="
                                        part.kind === 'author' &&
                                        !photo.author.url
                                    "
                                    >{{ photo.author.name }}</span
                                >
                                <a
                                    v-else-if="part.kind === 'author'"
                                    :href="photo.author.url ?? undefined"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="underline-offset-2 hover:text-foreground hover:underline"
                                    >{{ photo.author.name }}</a
                                >
                                <a
                                    v-else-if="part.kind === 'unsplash'"
                                    :href="photo.unsplash_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="underline-offset-2 hover:text-foreground hover:underline"
                                    >Unsplash</a
                                >
                                <template v-else>{{ part.value }}</template>
                            </template>
                        </figcaption>
                    </figure>
                </div>
                <p
                    v-if="failed"
                    role="alert"
                    class="py-6 text-center text-sm text-destructive"
                    data-testid="unsplash-error"
                >
                    {{ $t('posts.composer.unsplash.error') }}
                </p>
                <p
                    v-else-if="!loading && !photos.length"
                    class="py-12 text-center text-sm text-muted-foreground"
                    data-testid="unsplash-empty"
                >
                    {{ $t('posts.composer.unsplash.empty') }}
                </p>
                <div v-if="loading" class="flex justify-center py-6">
                    <IconLoader2
                        class="size-5 animate-spin text-muted-foreground"
                        aria-hidden="true"
                    />
                </div>
                <div
                    v-else-if="hasMore && !failed"
                    class="flex flex-col items-center pt-1 pb-2"
                >
                    <div ref="sentinel" class="h-px w-full" />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-testid="unsplash-load-more"
                        @click="loadMore"
                    >
                        {{ $t('posts.composer.unsplash.load_more') }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
