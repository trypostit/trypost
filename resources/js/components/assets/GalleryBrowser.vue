<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { IconCloudUpload, IconFileTypePdf, IconLoader2, IconPencilPlus, IconPhoto, IconPlus, IconSearch, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onMounted, onUnmounted, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import ImagePreviewDialog from '@/components/ImagePreviewDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import debounce from '@/debounce';
import { acceptAttribute, classify, isDocument, isVideo, MediaType } from '@/lib/mediaType';
import { destroy as assetsDestroy, search as assetsSearch, storeChunked as assetsStoreChunked, storeFromUrl } from '@/routes/app/assets';
import { search as giphySearch, trending as giphyTrending } from '@/routes/app/assets/giphy';
import { search as unsplashSearch, trending as unsplashTrending } from '@/routes/app/assets/unsplash';
import { store as storePost } from '@/routes/app/posts';
import { uploadChunked } from '@/utils/chunkedUpload';

interface AssetMedia {
    id: string;
    path: string;
    url: string;
    type: MediaType;
    mime_type: string;
    original_filename: string;
    size: number;
    meta: { width?: number; height?: number; duration?: number } | null;
    created_at: string;
}

interface UnsplashPhoto {
    id: string;
    url_small: string;
    url_regular: string;
    url_full: string;
    download_location: string;
    description: string | null;
    width: number;
    height: number;
    author: { name: string; url: string };
}

interface GiphyGif {
    id: string;
    title: string;
    url_preview: string;
    url_original: string;
    url_downsized: string;
    width: number;
    height: number;
    size: number;
}

interface SavedMedia {
    id: string;
    path: string;
    url: string;
    type: MediaType;
    mime_type: string;
}

interface PickedMedia {
    id: string;
    path: string;
    url: string;
    type: MediaType;
    mime_type: string;
    original_filename?: string;
    size?: number;
    meta?: { width?: number; height?: number; duration?: number };
    source?: 'ai' | 'unsplash' | 'giphy';
    source_meta?: Record<string, unknown>;
}

const props = defineProps<{
    mode: 'standalone' | 'picker';
}>();

const selected = defineModel<PickedMedia[]>('selected', { default: () => [] });

const isPicker = computed(() => props.mode === 'picker');

// The upload file picker accepts everything the backend's Media\Type allow-list does.
const acceptedUploadTypes = acceptAttribute();

const lightbox = ref<InstanceType<typeof ImagePreviewDialog> | null>(null);

const handleAssetClick = (asset: AssetMedia) => {
    if (isPicker.value) {
        toggleSelect(asset);
        return;
    }
    const items = uploads.value.map((a) => ({
        url: a.url,
        type: classify(a) ?? MediaType.Image,
    }));
    const idx = uploads.value.findIndex((a) => a.id === asset.id);
    lightbox.value?.openCollection(items, idx);
};

const previewUnsplashPhoto = (photo: UnsplashPhoto) => {
    const items = displayedPhotos.value.map((p) => ({
        url: p.url_regular,
        type: 'image' as const,
    }));
    const idx = displayedPhotos.value.findIndex((p) => p.id === photo.id);
    lightbox.value?.openCollection(items, idx);
};

const previewGiphyGif = (gif: GiphyGif) => {
    const items = displayedGifs.value.map((g) => ({
        url: g.url_original,
        type: 'image' as const,
    }));
    const idx = displayedGifs.value.findIndex((g) => g.id === gif.id);
    lightbox.value?.openCollection(items, idx);
};

const selectedIds = computed(() => new Set(selected.value.map((m) => m.id)));
const isSelected = (id: string) => selectedIds.value.has(id);
const selectionIndex = (id: string) => selected.value.findIndex((m) => m.id === id) + 1;

const toggleSelect = (asset: AssetMedia | SavedMedia, extra?: Partial<PickedMedia>) => {
    if (!isPicker.value) return;
    if (isSelected(asset.id)) {
        selected.value = selected.value.filter((m) => m.id !== asset.id);
    } else {
        selected.value = [
            ...selected.value,
            {
                id: asset.id,
                path: asset.path,
                url: asset.url,
                type: asset.type,
                mime_type: asset.mime_type,
                original_filename: 'original_filename' in asset ? asset.original_filename : undefined,
                size: 'size' in asset ? asset.size : undefined,
                meta: 'meta' in asset ? (asset.meta ?? undefined) : undefined,
                ...extra,
            },
        ];
    }
};

// ─── Uploads tab ────────────────────────────────────────────────
const uploads = ref<AssetMedia[]>([]);
const uploadsSearch = ref('');
const uploadsPage = ref(1);
const uploadsLastPage = ref(1);
const uploadsLoading = ref(false);
const uploadsLoadingMore = ref(false);
const uploadsHasMore = computed(() => uploadsPage.value < uploadsLastPage.value);

const fileInput = ref<HTMLInputElement | null>(null);
const uploadsSentinel = useTemplateRef<HTMLDivElement>('uploadsSentinel');
const isDragging = ref(false);
const uploading = ref(false);
let uploadsObserver: IntersectionObserver | null = null;

const fetchUploads = async (page: number, term: string) => {
    const response = await fetch(
        assetsSearch.url({ query: { search: term, page: String(page) } }),
        { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' },
    );
    if (!response.ok) throw new Error('Failed to load uploads');
    return (await response.json()) as { data: AssetMedia[]; meta: { current_page: number; last_page: number } };
};

const loadUploadsFirstPage = async () => {
    uploadsLoading.value = true;
    try {
        const response = await fetchUploads(1, uploadsSearch.value.trim());
        uploads.value = response.data;
        uploadsPage.value = response.meta.current_page;
        uploadsLastPage.value = response.meta.last_page;
    } catch {
        uploads.value = [];
    } finally {
        uploadsLoading.value = false;
    }
};

const loadMoreUploads = async () => {
    if (uploadsLoadingMore.value || !uploadsHasMore.value) return;
    uploadsLoadingMore.value = true;
    try {
        const response = await fetchUploads(uploadsPage.value + 1, uploadsSearch.value.trim());
        uploads.value.push(...response.data);
        uploadsPage.value = response.meta.current_page;
        uploadsLastPage.value = response.meta.last_page;
    } catch {
        // ignore
    } finally {
        uploadsLoadingMore.value = false;
    }
};

const debouncedUploadsSearch = debounce(() => {
    void loadUploadsFirstPage();
}, 300);

watch(uploadsSearch, () => debouncedUploadsSearch());

const setupUploadsObserver = () => {
    uploadsObserver?.disconnect();
    uploadsObserver = new IntersectionObserver(
        (entries) => {
            if (entries[0]?.isIntersecting && uploadsHasMore.value && !uploadsLoadingMore.value) {
                void loadMoreUploads();
            }
        },
        { rootMargin: '200px' },
    );
    if (uploadsSentinel.value) uploadsObserver.observe(uploadsSentinel.value);
};

watch(uploadsSentinel, async () => {
    await nextTick();
    setupUploadsObserver();
});

const triggerFileInput = () => fileInput.value?.click();
const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        void uploadFiles(Array.from(target.files));
        target.value = '';
    }
};
const handleDrop = (event: DragEvent) => {
    isDragging.value = false;
    if (event.dataTransfer?.files) {
        void uploadFiles(Array.from(event.dataTransfer.files));
    }
};

const uploadFiles = async (files: File[]) => {
    uploading.value = true;
    for (const file of files) {
        try {
            await uploadChunked({
                file,
                url: assetsStoreChunked.url(),
                collection: 'assets',
            });
        } catch {
            toast.error(trans('assets.upload.failed', { file: file.name }));
        }
    }
    uploading.value = false;
    await loadUploadsFirstPage();
};

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
const handleDelete = (assetId: string) => {
    deleteModal.value?.open({
        url: assetsDestroy.url(assetId),
        confirmText: trans('common.confirm_modal.delete_keyword'),
    });
};

const createPostFromAsset = (asset: AssetMedia) => {
    router.post(storePost.url(), {
        media: [{ id: asset.id, path: asset.path, url: asset.url, type: asset.type, mime_type: asset.mime_type }],
    });
};

// ─── Unsplash tab ──────────────────────────────────────────────
const httpUnsplash = useHttp<Record<string, never>, { results: UnsplashPhoto[]; total_pages?: number }>({});
const httpSaveFromUrl = useHttp<{ url: string; filename: string; download_location?: string }, SavedMedia>({
    url: '',
    filename: '',
});

const unsplashQuery = ref('');
const unsplashResults = ref<UnsplashPhoto[]>([]);
const unsplashPage = ref(1);
const unsplashTotalPages = ref(0);
const unsplashLoading = ref(false);
const trendingPhotos = ref<UnsplashPhoto[]>([]);
const trendingPage = ref(1);
const trendingHasMore = ref(true);
const savingPhotoId = ref<string | null>(null);
const unsplashSentinel = useTemplateRef<HTMLDivElement>('unsplashSentinel');
let unsplashObserver: IntersectionObserver | null = null;

const displayedPhotos = computed(() =>
    unsplashQuery.value && unsplashResults.value.length > 0
        ? unsplashResults.value
        : !unsplashQuery.value
          ? trendingPhotos.value
          : [],
);

const hasMorePhotos = computed(() =>
    unsplashQuery.value ? unsplashPage.value < unsplashTotalPages.value : trendingHasMore.value,
);

const loadTrending = async (page = 1) => {
    if (unsplashLoading.value) return;
    unsplashLoading.value = true;
    try {
        const response = await httpUnsplash.get(unsplashTrending.url({ query: { page: String(page) } }));
        const results = response?.results ?? [];
        if (page === 1) trendingPhotos.value = results;
        else trendingPhotos.value.push(...results);
        trendingPage.value = page;
        trendingHasMore.value = results.length >= 25;
    } catch {
        // ignore
    } finally {
        unsplashLoading.value = false;
    }
};

const searchUnsplashFn = debounce(async () => {
    if (!unsplashQuery.value.trim()) {
        unsplashResults.value = [];
        return;
    }
    unsplashLoading.value = true;
    unsplashPage.value = 1;
    try {
        const response = await httpUnsplash.get(unsplashSearch.url({ query: { query: unsplashQuery.value, page: '1' } }));
        unsplashResults.value = response?.results ?? [];
        unsplashTotalPages.value = response?.total_pages ?? 0;
    } catch {
        unsplashResults.value = [];
    } finally {
        unsplashLoading.value = false;
    }
}, 400);

const loadMoreUnsplash = async () => {
    if (unsplashPage.value >= unsplashTotalPages.value || unsplashLoading.value) return;
    unsplashLoading.value = true;
    unsplashPage.value++;
    try {
        const response = await httpUnsplash.get(
            unsplashSearch.url({ query: { query: unsplashQuery.value, page: String(unsplashPage.value) } }),
        );
        unsplashResults.value.push(...(response?.results ?? []));
    } catch {
        // ignore
    } finally {
        unsplashLoading.value = false;
    }
};

const loadMorePhotosOnScroll = async () => {
    if (unsplashLoading.value) return;
    if (unsplashQuery.value) await loadMoreUnsplash();
    else await loadTrending(trendingPage.value + 1);
};

const setupUnsplashObserver = () => {
    if (unsplashObserver) unsplashObserver.disconnect();
    unsplashObserver = new IntersectionObserver(
        (entries) => {
            if (entries[0]?.isIntersecting && hasMorePhotos.value && !unsplashLoading.value) {
                void loadMorePhotosOnScroll();
            }
        },
        { rootMargin: '200px' },
    );
    if (unsplashSentinel.value) unsplashObserver.observe(unsplashSentinel.value);
};

const saveMediaFromUrl = async (payload: { url: string; filename: string; download_location?: string }): Promise<SavedMedia | null> => {
    httpSaveFromUrl.url = payload.url;
    httpSaveFromUrl.filename = payload.filename;
    httpSaveFromUrl.download_location = payload.download_location;
    try {
        return (await httpSaveFromUrl.post(storeFromUrl.url())) ?? null;
    } catch {
        return null;
    }
};

const saveAndPickUnsplash = async (photo: UnsplashPhoto) => {
    savingPhotoId.value = photo.id;
    const media = await saveMediaFromUrl({
        url: photo.url_regular,
        filename: `unsplash-${photo.id}.jpg`,
        download_location: photo.download_location,
    });
    savingPhotoId.value = null;
    if (!media) return;

    if (isPicker.value) {
        toggleSelect(media, { source: 'unsplash', source_meta: { photo_id: photo.id } });
    } else {
        toast.success(trans('assets.saved'));
        await loadUploadsFirstPage();
    }
};

const createPostFromUnsplash = async (photo: UnsplashPhoto) => {
    savingPhotoId.value = photo.id;
    const media = await saveMediaFromUrl({
        url: photo.url_regular,
        filename: `unsplash-${photo.id}.jpg`,
        download_location: photo.download_location,
    });
    if (!media) {
        savingPhotoId.value = null;
        return;
    }
    router.post(storePost.url(), {
        media: [{
            id: media.id,
            path: media.path,
            url: media.url,
            type: media.type,
            mime_type: media.mime_type,
            source: 'unsplash',
            source_meta: { photo_id: photo.id },
        }],
    });
};

// ─── Giphy tab ─────────────────────────────────────────────────
const httpGiphy = useHttp<Record<string, never>, { results: GiphyGif[]; total_pages?: number }>({});

const giphyQuery = ref('');
const giphyResults = ref<GiphyGif[]>([]);
const giphyPage = ref(1);
const giphyTotalPages = ref(0);
const giphyLoading = ref(false);
const giphyTrendingItems = ref<GiphyGif[]>([]);
const giphyTrendingPage = ref(1);
const giphyTrendingHasMore = ref(true);
const savingGifId = ref<string | null>(null);
const giphySentinel = useTemplateRef<HTMLDivElement>('giphySentinel');
let giphyObserver: IntersectionObserver | null = null;

const displayedGifs = computed(() =>
    giphyQuery.value && giphyResults.value.length > 0
        ? giphyResults.value
        : !giphyQuery.value
          ? giphyTrendingItems.value
          : [],
);

const hasMoreGifs = computed(() =>
    giphyQuery.value ? giphyPage.value < giphyTotalPages.value : giphyTrendingHasMore.value,
);

const loadGiphyTrending = async (page = 1) => {
    if (giphyLoading.value) return;
    giphyLoading.value = true;
    try {
        const response = await httpGiphy.get(giphyTrending.url({ query: { page: String(page) } }));
        const results = response?.results ?? [];
        if (page === 1) giphyTrendingItems.value = results;
        else giphyTrendingItems.value.push(...results);
        giphyTrendingPage.value = page;
        giphyTrendingHasMore.value = results.length >= 25;
    } catch {
        // ignore
    } finally {
        giphyLoading.value = false;
    }
};

const searchGiphyFn = debounce(async () => {
    if (!giphyQuery.value.trim()) {
        giphyResults.value = [];
        return;
    }
    giphyLoading.value = true;
    giphyPage.value = 1;
    try {
        const response = await httpGiphy.get(giphySearch.url({ query: { query: giphyQuery.value, page: '1' } }));
        giphyResults.value = response?.results ?? [];
        giphyTotalPages.value = response?.total_pages ?? 0;
    } catch {
        giphyResults.value = [];
    } finally {
        giphyLoading.value = false;
    }
}, 400);

const loadMoreGiphy = async () => {
    if (giphyPage.value >= giphyTotalPages.value || giphyLoading.value) return;
    giphyLoading.value = true;
    giphyPage.value++;
    try {
        const response = await httpGiphy.get(giphySearch.url({ query: { query: giphyQuery.value, page: String(giphyPage.value) } }));
        giphyResults.value.push(...(response?.results ?? []));
    } catch {
        // ignore
    } finally {
        giphyLoading.value = false;
    }
};

const loadMoreGifsOnScroll = async () => {
    if (giphyLoading.value) return;
    if (giphyQuery.value) await loadMoreGiphy();
    else await loadGiphyTrending(giphyTrendingPage.value + 1);
};

const setupGiphyObserver = () => {
    if (giphyObserver) giphyObserver.disconnect();
    giphyObserver = new IntersectionObserver(
        (entries) => {
            if (entries[0]?.isIntersecting && hasMoreGifs.value && !giphyLoading.value) {
                void loadMoreGifsOnScroll();
            }
        },
        { rootMargin: '200px' },
    );
    if (giphySentinel.value) giphyObserver.observe(giphySentinel.value);
};

const saveAndPickGiphy = async (gif: GiphyGif) => {
    savingGifId.value = gif.id;
    const media = await saveMediaFromUrl({
        url: gif.url_downsized,
        filename: `giphy-${gif.id}.gif`,
    });
    savingGifId.value = null;
    if (!media) return;

    if (isPicker.value) {
        toggleSelect(media, { source: 'giphy', source_meta: { gif_id: gif.id } });
    } else {
        toast.success(trans('assets.saved'));
        await loadUploadsFirstPage();
    }
};

const createPostFromGiphy = async (gif: GiphyGif) => {
    savingGifId.value = gif.id;
    const media = await saveMediaFromUrl({
        url: gif.url_downsized,
        filename: `giphy-${gif.id}.gif`,
    });
    if (!media) {
        savingGifId.value = null;
        return;
    }
    router.post(storePost.url(), {
        media: [{
            id: media.id,
            path: media.path,
            url: media.url,
            type: media.type,
            mime_type: media.mime_type,
            source: 'giphy',
            source_meta: { gif_id: gif.id },
        }],
    });
};

// ─── Lifecycle ─────────────────────────────────────────────────
const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1048576).toFixed(1)} MB`;
};

const initialize = async () => {
    await loadUploadsFirstPage();
};

const onUnsplashTabMounted = async () => {
    if (trendingPhotos.value.length === 0) await loadTrending();
    await nextTick();
    setupUnsplashObserver();
};

const onGiphyTabMounted = async () => {
    if (giphyTrendingItems.value.length === 0) await loadGiphyTrending();
    await nextTick();
    setupGiphyObserver();
};

defineExpose({ initialize, refreshUploads: loadUploadsFirstPage });

onMounted(() => {
    void initialize();
});

onUnmounted(() => {
    uploadsObserver?.disconnect();
    unsplashObserver?.disconnect();
    giphyObserver?.disconnect();
});
</script>

<template>
    <div>
        <Tabs default-value="uploads">
            <TabsList>
                <TabsTrigger value="uploads">{{ trans('assets.tabs.my_uploads') }}</TabsTrigger>
                <TabsTrigger value="stock">{{ trans('assets.tabs.stock_photos') }}</TabsTrigger>
                <TabsTrigger value="gifs">{{ trans('assets.tabs.gifs') }}</TabsTrigger>
            </TabsList>

            <!-- ───── My Uploads ───── -->
            <TabsContent value="uploads" class="mt-6">
                <div
                    class="relative mb-4 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-8 text-center transition-colors"
                    :class="isDragging ? 'border-foreground bg-violet-100' : 'border-foreground/25 bg-card hover:bg-foreground/5'"
                    @click="triggerFileInput"
                    @dragover.prevent="isDragging = true"
                    @dragleave.prevent="isDragging = false"
                    @drop.prevent="handleDrop"
                >
                    <div class="inline-flex size-12 -rotate-3 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-2xs">
                        <IconCloudUpload class="size-6 text-foreground" stroke-width="2" />
                    </div>
                    <p class="text-sm font-semibold text-foreground">{{ trans('assets.upload.drag_drop') }}</p>
                    <p class="text-xs text-foreground/60">{{ trans('assets.upload.formats') }}</p>
                    <input
                        ref="fileInput"
                        type="file"
                        class="hidden"
                        multiple
                        :accept="acceptedUploadTypes"
                        @change="handleFileSelect"
                    />
                    <div v-if="uploading" class="absolute inset-0 flex items-center justify-center rounded-2xl bg-card/85">
                        <div class="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <IconLoader2 class="size-4 animate-spin" />
                            {{ trans('assets.upload.uploading') }}
                        </div>
                    </div>
                </div>

                <div class="relative mb-4">
                    <IconSearch class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-foreground/60" />
                    <Input
                        v-model="uploadsSearch"
                        type="search"
                        :placeholder="trans('assets.search_placeholder')"
                        class="h-12 pl-11 text-base"
                    />
                </div>

                <div v-if="uploadsLoading" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    <Skeleton v-for="i in 8" :key="i" class="aspect-square rounded-xl" />
                </div>

                <EmptyState
                    v-else-if="uploads.length === 0"
                    :icon="IconPhoto"
                    :title="trans('assets.empty.title')"
                    :description="trans('assets.empty.description')"
                />

                <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    <div
                        v-for="asset in uploads"
                        :key="asset.id"
                        class="group relative overflow-hidden rounded-xl border-2 border-foreground bg-muted shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                        :class="[
                            'cursor-pointer',
                            isPicker && isSelected(asset.id) ? 'ring-2 ring-primary ring-offset-2 ring-offset-background' : '',
                        ]"
                        @click="handleAssetClick(asset)"
                    >
                        <div class="aspect-square">
                            <video
                                v-if="isVideo(asset)"
                                :src="asset.url"
                                class="size-full object-cover"
                                muted
                                preload="metadata"
                            />
                            <div
                                v-else-if="isDocument(asset)"
                                class="flex size-full flex-col items-center justify-center gap-1.5 bg-rose-50 p-3 text-center"
                            >
                                <IconFileTypePdf class="size-9 text-rose-600" />
                                <span class="line-clamp-2 break-all text-[11px] font-medium text-foreground/70">{{ asset.original_filename }}</span>
                            </div>
                            <img
                                v-else
                                :src="asset.url"
                                :alt="asset.original_filename"
                                class="size-full object-cover"
                                loading="lazy"
                            />
                        </div>

                        <div
                            v-if="isPicker && isSelected(asset.id)"
                            class="absolute right-2 top-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground bg-primary text-xs font-bold text-primary-foreground shadow-2xs"
                        >
                            {{ selectionIndex(asset.id) }}
                        </div>

                        <div
                            v-if="!isPicker"
                            class="absolute inset-0 flex flex-col justify-between bg-transparent p-2 opacity-100 transition-opacity lg:bg-foreground/60 lg:opacity-0 lg:group-hover:opacity-100"
                        >
                            <div class="flex justify-end gap-1.5">
                                <TooltipProvider>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button variant="outline" size="icon" class="size-8" @click.stop="createPostFromAsset(asset)">
                                                <IconPencilPlus class="size-4" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>{{ trans('assets.create_post') }}</TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="size-8 bg-rose-100 hover:bg-rose-200"
                                    data-testid="gallery-asset-delete"
                                    @click.stop="handleDelete(asset.id)"
                                >
                                    <IconTrash class="size-4 text-rose-700" />
                                </Button>
                            </div>
                            <div class="space-y-0.5">
                                <p class="truncate text-xs font-semibold text-white">{{ asset.original_filename }}</p>
                                <p class="text-xs text-white/70">{{ formatFileSize(asset.size) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="uploadsHasMore" ref="uploadsSentinel" class="mt-4 flex justify-center">
                    <IconLoader2 v-if="uploadsLoadingMore" class="size-5 animate-spin text-foreground/60" />
                </div>
            </TabsContent>

            <!-- ───── Stock Photos (Unsplash) ───── -->
            <TabsContent value="stock" class="mt-6" @vue:mounted="onUnsplashTabMounted">
                <div class="relative mb-4">
                    <IconSearch class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-foreground/60" />
                    <Input
                        v-model="unsplashQuery"
                        :placeholder="trans('assets.unsplash.search_placeholder')"
                        class="h-12 pl-11 text-base"
                        @input="searchUnsplashFn"
                    />
                </div>

                <div v-if="displayedPhotos.length > 0" class="space-y-3">
                    <p
                        v-if="!unsplashQuery && trendingPhotos.length > 0"
                        class="text-[11px] font-black uppercase tracking-widest text-foreground/60"
                    >
                        {{ trans('assets.unsplash.trending') }}
                    </p>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                        <div
                            v-for="photo in displayedPhotos"
                            :key="photo.id"
                            class="group relative cursor-pointer overflow-hidden rounded-xl border-2 border-foreground bg-muted shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                            @click="previewUnsplashPhoto(photo)"
                        >
                            <div class="aspect-[4/3]">
                                <img
                                    :src="photo.url_small"
                                    :alt="photo.description || 'Unsplash photo'"
                                    class="size-full object-cover"
                                    loading="lazy"
                                />
                            </div>

                            <div class="absolute inset-0 flex flex-col justify-between bg-transparent p-2 opacity-100 transition-opacity lg:bg-foreground/60 lg:opacity-0 lg:group-hover:opacity-100">
                                <div class="flex justify-end gap-1.5">
                                    <TooltipProvider v-if="!isPicker">
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="outline"
                                                    size="icon"
                                                    class="size-8"
                                                    :disabled="savingPhotoId === photo.id"
                                                    @click.stop="createPostFromUnsplash(photo)"
                                                >
                                                    <IconPencilPlus class="size-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>{{ trans('assets.create_post') }}</TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="outline"
                                                    size="icon"
                                                    class="size-8 bg-violet-100 hover:bg-violet-200"
                                                    :disabled="savingPhotoId === photo.id"
                                                    @click.stop="saveAndPickUnsplash(photo)"
                                                >
                                                    <IconLoader2 v-if="savingPhotoId === photo.id" class="size-4 animate-spin" />
                                                    <IconPlus v-else class="size-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                {{ isPicker ? trans('assets.add_to_post') : trans('assets.save_to_assets') }}
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </div>
                                <p class="text-xs text-white/80">
                                    <a :href="photo.author.url + '?utm_source=trypost&utm_medium=referral'" target="_blank" rel="noopener noreferrer" class="hover:text-white" @click.stop>
                                        {{ photo.author.name }}
                                    </a>
                                    <span class="text-white/50"> / </span>
                                    <a href="https://unsplash.com/?utm_source=trypost&utm_medium=referral" target="_blank" rel="noopener noreferrer" class="hover:text-white" @click.stop>
                                        Unsplash
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <EmptyState
                    v-else-if="unsplashQuery && !unsplashLoading"
                    :icon="IconSearch"
                    :title="trans('assets.unsplash.no_results')"
                    :description="trans('assets.unsplash.no_results_description')"
                />

                <div v-if="unsplashLoading" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    <Skeleton v-for="i in 8" :key="i" class="aspect-[4/3] rounded-xl" />
                </div>

                <div v-if="hasMorePhotos" ref="unsplashSentinel" class="h-1" />
            </TabsContent>

            <!-- ───── GIFs (Giphy) ───── -->
            <TabsContent value="gifs" class="mt-6" @vue:mounted="onGiphyTabMounted">
                <div class="relative mb-4">
                    <IconSearch class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-foreground/60" />
                    <Input
                        v-model="giphyQuery"
                        :placeholder="trans('assets.giphy.search_placeholder')"
                        class="h-12 pl-11 text-base"
                        @input="searchGiphyFn"
                    />
                </div>

                <div v-if="displayedGifs.length > 0" class="space-y-3">
                    <p
                        v-if="!giphyQuery && giphyTrendingItems.length > 0"
                        class="text-[11px] font-black uppercase tracking-widest text-foreground/60"
                    >
                        {{ trans('assets.giphy.trending') }}
                    </p>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                        <div
                            v-for="gif in displayedGifs"
                            :key="gif.id"
                            class="group relative cursor-pointer overflow-hidden rounded-xl border-2 border-foreground bg-muted shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                            @click="previewGiphyGif(gif)"
                        >
                            <div class="aspect-[4/3]">
                                <img :src="gif.url_preview" :alt="gif.title || 'GIF'" class="size-full object-cover" loading="lazy" />
                            </div>

                            <div class="absolute inset-0 flex flex-col justify-between bg-transparent p-2 opacity-100 transition-opacity lg:bg-foreground/60 lg:opacity-0 lg:group-hover:opacity-100">
                                <div class="flex justify-end gap-1.5">
                                    <TooltipProvider v-if="!isPicker">
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="outline"
                                                    size="icon"
                                                    class="size-8"
                                                    :disabled="savingGifId === gif.id"
                                                    @click.stop="createPostFromGiphy(gif)"
                                                >
                                                    <IconPencilPlus class="size-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>{{ trans('assets.create_post') }}</TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <Button
                                                    variant="outline"
                                                    size="icon"
                                                    class="size-8 bg-violet-100 hover:bg-violet-200"
                                                    :disabled="savingGifId === gif.id"
                                                    @click.stop="saveAndPickGiphy(gif)"
                                                >
                                                    <IconLoader2 v-if="savingGifId === gif.id" class="size-4 animate-spin" />
                                                    <IconPlus v-else class="size-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                {{ isPicker ? trans('assets.add_to_post') : trans('assets.save_to_assets') }}
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </div>
                                <p v-if="gif.title" class="truncate text-xs text-white/80">{{ gif.title }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <EmptyState
                    v-else-if="giphyQuery && !giphyLoading"
                    :icon="IconSearch"
                    :title="trans('assets.giphy.no_results')"
                    :description="trans('assets.giphy.no_results_description')"
                />

                <div v-if="giphyLoading" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    <Skeleton v-for="i in 8" :key="i" class="aspect-[4/3] rounded-xl" />
                </div>

                <div v-if="hasMoreGifs" ref="giphySentinel" class="h-1" />

                <div v-if="displayedGifs.length > 0" class="mt-4 text-center">
                    <a href="https://giphy.com" target="_blank" rel="noopener noreferrer" class="text-xs font-medium text-foreground/60 hover:text-foreground">
                        {{ trans('assets.giphy.powered_by') }}
                    </a>
                </div>
            </TabsContent>
        </Tabs>

        <ConfirmDeleteModal
            ref="deleteModal"
            :title="trans('assets.delete.title')"
            :description="trans('assets.delete.description')"
            :action="trans('assets.delete.confirm')"
            :cancel="trans('assets.delete.cancel')"
        />

        <ImagePreviewDialog ref="lightbox" />
    </div>
</template>
