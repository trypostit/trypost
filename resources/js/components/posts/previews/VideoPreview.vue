<script setup lang="ts">
import { IconPlayerPlayFilled } from '@tabler/icons-vue';
import { ref } from 'vue';

import { useSoundtrackVideo } from '@/composables/composer/useReelSoundtrack';

const props = withDefaults(
    defineProps<{
        src: string;
        videoClass?: string;
    }>(),
    {
        videoClass: 'w-full h-full object-cover',
    },
);

const videoRef = ref<HTMLVideoElement | null>(null);
const isPlaying = ref(false);

const soundtrack = useSoundtrackVideo(videoRef);

const markPlaying = (): void => {
    isPlaying.value = true;
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
            :src="props.src"
            :class="props.videoClass"
            playsinline
            preload="metadata"
            @play="markPlaying"
            @pause="markPaused"
            @ended="markPaused"
        />
        <button
            v-show="!isPlaying"
            type="button"
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
