<script setup lang="ts">
import { computed } from 'vue';

import { storedCoverSource } from '@/lib/mediaEditor';
import { videoFrameUrl } from '@/lib/videoFrame';
import type { MediaItem } from '@/types/media';

/**
 * A video shown still, as its cover: the image uploaded as its cover, else
 * the video painted at its picked frame, else at its start. A frame's
 * screenshot (kept for the networks that take an image) is that same frame,
 * so the video shows it. Classes and test ids fall through.
 */
const props = defineProps<{
    item: Pick<MediaItem, 'url' | 'meta'>;
}>();

const coverImageUrl = computed(() => {
    if (storedCoverSource(props.item) !== 'image') {
        return null;
    }

    return props.item.meta?.cover?.url ?? null;
});
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
