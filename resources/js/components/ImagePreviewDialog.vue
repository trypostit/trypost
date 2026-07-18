<script setup lang="ts">
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-vue';
import { computed, onUnmounted, ref, watch } from 'vue';

import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { MediaType } from '@/lib/mediaType';

interface PreviewItem {
    url: string;
    type: MediaType;
    altText?: string;
}

const items = ref<PreviewItem[]>([]);
const index = ref<number | null>(null);

const isOpen = computed({
    get: () => index.value !== null && items.value.length > 0,
    set: (val) => {
        if (!val) close();
    },
});

const safeIndex = computed(() =>
    Math.max(0, Math.min(index.value ?? 0, items.value.length - 1)),
);
const currentItem = computed(() => items.value[safeIndex.value] ?? null);
const hasPrev = computed(() => safeIndex.value > 0);
const hasNext = computed(() => safeIndex.value < items.value.length - 1);
const showNav = computed(() => items.value.length > 1);

const open = (url: string, type: MediaType = MediaType.Image) => {
    items.value = [{ url, type }];
    index.value = 0;
};

const openCollection = (collection: PreviewItem[], startIndex = 0) => {
    if (collection.length === 0) return;
    items.value = [...collection];
    index.value = Math.max(0, Math.min(startIndex, collection.length - 1));
};

const close = () => {
    index.value = null;
};

const goPrev = () => {
    if (hasPrev.value) index.value = safeIndex.value - 1;
};

const goNext = () => {
    if (hasNext.value) index.value = safeIndex.value + 1;
};

const onKeydown = (e: KeyboardEvent) => {
    if (!isOpen.value) return;
    if (e.key === 'ArrowLeft') {
        e.preventDefault();
        goPrev();
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        goNext();
    }
};

watch(
    isOpen,
    (open) => {
        if (open) {
            window.addEventListener('keydown', onKeydown);
        } else {
            window.removeEventListener('keydown', onKeydown);
        }
    },
    { immediate: true },
);

onUnmounted(() => window.removeEventListener('keydown', onKeydown));

defineExpose({ open, openCollection, close });
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent
            class="max-w-5xl gap-0 border-0 bg-transparent p-0 shadow-none outline-none focus:outline-none focus-visible:outline-none sm:max-w-5xl"
            :show-close-button="false"
        >
            <DialogTitle class="sr-only">Media preview</DialogTitle>
            <div class="relative flex justify-center" @click.self="close">
                <img
                    v-if="currentItem && currentItem.type === 'image'"
                    :src="currentItem.url"
                    alt="Preview"
                    class="max-h-[85vh] max-w-full cursor-pointer rounded-2xl object-contain"
                    @click="close"
                />

                <video
                    v-else-if="currentItem && currentItem.type === 'video'"
                    :key="currentItem.url"
                    :src="currentItem.url"
                    data-testid="lightbox-video"
                    class="max-h-[85vh] max-w-full rounded-2xl bg-black"
                    controls
                    autoplay
                    preload="metadata"
                    playsinline
                    @click.stop
                />

                <iframe
                    v-else-if="currentItem && currentItem.type === 'document'"
                    :key="currentItem.url"
                    :src="currentItem.url"
                    title="PDF preview"
                    class="h-[85vh] w-full rounded-2xl bg-white"
                    @click.stop
                />

                <button
                    v-if="showNav && hasPrev"
                    type="button"
                    aria-label="Previous"
                    class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-black/50 p-2 text-white transition hover:bg-black/70"
                    @click.stop="goPrev"
                >
                    <IconChevronLeft class="size-6" />
                </button>

                <button
                    v-if="showNav && hasNext"
                    type="button"
                    aria-label="Next"
                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-black/50 p-2 text-white transition hover:bg-black/70"
                    @click.stop="goNext"
                >
                    <IconChevronRight class="size-6" />
                </button>

                <div
                    v-if="currentItem?.altText"
                    data-testid="lightbox-alt-text"
                    class="pointer-events-none absolute left-1/2 max-w-[calc(100%-2rem)] -translate-x-1/2 rounded-lg bg-black/70 px-4 py-2 text-center text-sm text-white backdrop-blur-sm sm:max-w-2xl"
                    :class="showNav ? 'bottom-14' : 'bottom-4'"
                >
                    {{ currentItem.altText }}
                </div>

                <div
                    v-if="showNav"
                    class="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-black/60 px-3 py-1 text-xs text-white tabular-nums"
                >
                    {{ safeIndex + 1 }} / {{ items.length }}
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
