<script setup lang="ts">
import { IconChevronLeft, IconChevronRight, IconPhoto } from '@tabler/icons-vue';
import { computed, ref, watch, type Component } from 'vue';

import VideoPreview from '@/components/posts/previews/VideoPreview.vue';
import { isVideoMedia } from '@/composables/useMedia';
import type { MediaItem } from '@/types/media';

interface Props {
    media: MediaItem[];
    placeholderIcon?: Component;
    showArrows?: boolean;
    showDots?: boolean;
    dotActiveClass?: string;
    dotInactiveClass?: string;
    mediaClass?: string;
    placeholderClass?: string;
}

const props = withDefaults(defineProps<Props>(), {
    placeholderIcon: () => IconPhoto,
    showArrows: true,
    showDots: true,
    dotActiveClass: 'bg-white',
    dotInactiveClass: 'bg-white/50 hover:bg-white/70',
    mediaClass: 'w-full h-full object-cover',
    placeholderClass: 'w-full h-full flex items-center justify-center bg-muted',
});

const currentIndex = ref(0);

watch(
    () => props.media.length,
    () => {
        if (currentIndex.value >= props.media.length) {
            currentIndex.value = Math.max(0, props.media.length - 1);
        }
    },
);

const hasMultiple = computed(() => props.media.length > 1);

const goToPrevious = () => {
    if (currentIndex.value > 0) currentIndex.value--;
};

const goToNext = () => {
    if (currentIndex.value < props.media.length - 1) currentIndex.value++;
};

const goToSlide = (index: number) => {
    currentIndex.value = index;
};
</script>

<template>
    <template v-if="media.length > 0">
        <template v-for="(item, index) in media" :key="item.id">
            <VideoPreview
                v-if="isVideoMedia(item) && index === currentIndex"
                :media="item"
                :video-class="mediaClass"
            />
            <img
                v-else-if="index === currentIndex"
                :src="item.url"
                :alt="item.original_filename"
                :class="mediaClass"
            />
        </template>

        <template v-if="hasMultiple && showArrows">
            <button
                v-if="currentIndex > 0"
                type="button"
                class="absolute left-1.5 top-1/2 z-10 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow-sm transition-colors hover:bg-white"
                @click="goToPrevious"
            >
                <IconChevronLeft class="h-4 w-4 text-foreground" />
            </button>
            <button
                v-if="currentIndex < media.length - 1"
                type="button"
                class="absolute right-1.5 top-1/2 z-10 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow-sm transition-colors hover:bg-white"
                @click="goToNext"
            >
                <IconChevronRight class="h-4 w-4 text-foreground" />
            </button>
        </template>

        <div
            v-if="hasMultiple && showDots"
            class="absolute bottom-2 left-1/2 flex -translate-x-1/2 gap-1"
        >
            <button
                v-for="(_, i) in media"
                :key="i"
                type="button"
                class="h-[6px] w-[6px] rounded-full transition-colors"
                :class="i === currentIndex ? dotActiveClass : dotInactiveClass"
                @click="goToSlide(i)"
            />
        </div>
    </template>

    <div v-else :class="placeholderClass">
        <component :is="placeholderIcon" class="h-12 w-12 text-muted-foreground/40" />
    </div>
</template>
