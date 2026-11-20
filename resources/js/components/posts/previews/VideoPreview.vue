<script setup lang="ts">
import { IconPlayerPlayFilled } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import VideoStill from '@/components/media/VideoStill.vue';
import { useSoundtrackVideo } from '@/composables/composer/useReelSoundtrack';
import { hasPickedCover } from '@/lib/videoFrame';
import type { MediaItem } from '@/types/media';

const props = withDefaults(
    defineProps<{
        media: Pick<MediaItem, 'url' | 'meta'>;
        videoClass?: string;
    }>(),
    {
        videoClass: 'w-full h-full object-cover',
    },
);

const videoRef = ref<HTMLVideoElement | null>(null);
const isPlaying = ref(false);
const hasPlayed = ref(false);

/** The picked cover stands in for the video until it is first played. */
const showsCover = computed(() => {
    if (hasPlayed.value) {
        return false;
    }

    return hasPickedCover(props.media);
});

const soundtrack = useSoundtrackVideo(videoRef);

const coverClass = computed(() => `absolute inset-0 ${props.videoClass}`);

const markPlaying = (): void => {
    isPlaying.value = true;
    hasPlayed.value = true;
    soundtrack.onVideoPlay();
};

const markPaused = (): void => {
    isPlaying.value = false;
    soundtrack.onVideoPause();
};

const toggle = () => {
    const el = videoRef.value;
    if (!el) return;
    if (el.paused) {
        void el.play();
    } else {
        el.pause();
    }
};
</script>

<template>
    <div class="relative h-full w-full" @click="toggle">
        <video
            ref="videoRef"
            :src="props.media.url"
            :class="props.videoClass"
            playsinline
            preload="metadata"
            @play="markPlaying"
            @pause="markPaused"
            @ended="markPaused"
        />
        <VideoStill
            v-if="showsCover"
            :item="props.media"
            :class="coverClass"
            data-testid="video-preview-cover"
        />
        <button
            v-show="!isPlaying"
            type="button"
            data-testid="video-preview-play"
            class="absolute inset-0 flex cursor-pointer items-center justify-center"
            :aria-label="$t('common.play')"
        >
            <span
                class="flex size-12 items-center justify-center rounded-full bg-white/90 transition-transform hover:scale-105"
            >
                <IconPlayerPlayFilled class="size-6 text-black" />
            </span>
        </button>
    </div>
</template>
