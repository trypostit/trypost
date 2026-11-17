<script setup lang="ts">
import { computed } from 'vue';

import { videoFrameUrl } from '@/lib/videoFrame';
import type { MediaItem } from '@/types/media';

/**
 * A video shown still, as its cover: the image picked as its cover
 * (`meta.cover`), else the video painted at its picked frame, else at its
 * start. Classes and test ids fall through to the element drawn.
 */
const props = defineProps<{
    item: Pick<MediaItem, 'url' | 'meta'>;
}>();

const coverImageUrl = computed(() => props.item.meta?.cover?.url);
const frameUrl = computed(() => videoFrameUrl(props.item));
</script>

<template>
    <img v-if="coverImageUrl" :src="coverImageUrl" alt="" draggable="false" />
    <video
        v-else
        :src="frameUrl"
        muted
        playsinline
        preload="metadata"
        draggable="false"
    />
</template>
