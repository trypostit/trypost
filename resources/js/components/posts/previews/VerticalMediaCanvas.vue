<script setup lang="ts">
import { IconPhoto } from '@tabler/icons-vue';
import { computed } from 'vue';

import VideoPreview from '@/components/posts/previews/VideoPreview.vue';
import { isVideoMedia } from '@/composables/useMedia';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    media: MediaItem[];
}>();

const item = computed<MediaItem | null>(() => props.media[0] ?? null);
</script>

<template>
    <div class="absolute inset-0 overflow-hidden bg-black">
        <template v-if="item">
            <VideoPreview v-if="isVideoMedia(item)" :src="item.url" video-class="h-full w-full object-cover" />
            <template v-else>
                <div class="absolute inset-x-0 top-0 h-1/2 overflow-hidden">
                    <img :src="item.url" alt="" aria-hidden="true" class="h-full w-full blur-3xl brightness-110" />
                </div>
                <div class="absolute inset-x-0 bottom-0 h-1/2 -scale-y-100 overflow-hidden">
                    <img :src="item.url" alt="" aria-hidden="true" class="h-full w-full blur-3xl brightness-110" />
                </div>
                <img :src="item.url" :alt="item.original_filename"
                    class="absolute inset-0 h-full w-full object-contain" />
            </template>
        </template>
        <template v-else>
            <slot name="placeholder">
                <div class="flex h-full w-full items-center justify-center">
                    <IconPhoto class="h-12 w-12 text-muted-foreground/40" />
                </div>
            </slot>
        </template>
    </div>
</template>
