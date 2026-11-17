<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { clamp, filled } from '@/lib/helpers';
import { formatCoverOffset, type MediaEdit } from '@/lib/mediaEditor';
import { releaseVideo } from '@/lib/videoFrame';

/** How many frames the strip shows across the video. */
const FRAME_COUNT = 8;

/**
 * The height each frame is drawn at, in canvas pixels: twice the strip's
 * 80px, sharp on a retina screen, never the video's full size.
 */
const FRAME_HEIGHT = 160;

const props = defineProps<{
    src: string;
    duration: number | null;
}>();

const edit = defineModel<MediaEdit>('edit', { required: true });

const frames = ref<HTMLCanvasElement[]>([]);
const paintedFrames = ref<number[]>([]);
const selectedVideo = ref<HTMLVideoElement | null>(null);
let stripVideo: HTMLVideoElement | null = null;

const maxSeconds = computed(() =>
    Math.max(0, Math.floor((props.duration ?? 0) * 10) / 10),
);
const seconds = computed({
    get: () => (edit.value.coverOffsetMs ?? 0) / 1000,
    set: (value: number) => {
        edit.value.coverOffsetMs = Math.round(value * 1000);
    },
});

const pickedTime = computed(() => formatCoverOffset(edit.value.coverOffsetMs ?? 0));

/** Where the picked frame sits on the strip, from 0 (start) to 1 (end). */
const position = computed(() => {
    if (maxSeconds.value <= 0) {
        return 0;
    }

    return clamp(seconds.value / maxSeconds.value, 0, 1);
});

/** The selector is one frame wide and slides from the first frame to the last. */
const selectorStyle = computed(() => ({
    width: `${100 / FRAME_COUNT}%`,
    left: `calc(${position.value} * (100% - 100% / ${FRAME_COUNT}))`,
}));

const showSelectedFrame = (): void => {
    const video = selectedVideo.value;

    if (filled(video) && video.readyState > 0) {
        video.currentTime = seconds.value;
    }
};

const seekTo = (video: HTMLVideoElement, time: number): Promise<void> =>
    new Promise((resolve) => {
        video.addEventListener('seeked', () => resolve(), { once: true });
        video.currentTime = time;
    });

/**
 * Paints the strip from one hidden copy of the video, a frame at a time.
 * Drawing a frame from another host only shows it; nothing is exported here,
 * so the video needs no CORS.
 */
const paintFrames = async (): Promise<void> => {
    const video = stripVideo;

    if (!filled(video)) {
        return;
    }

    for (const [index, canvas] of frames.value.entries()) {
        await seekTo(video, ((index + 0.5) / FRAME_COUNT) * video.duration);

        if (stripVideo !== video) {
            return;
        }

        const scale = Math.min(1, FRAME_HEIGHT / video.videoHeight);
        canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
        canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);
        paintedFrames.value.push(index);
    }
};

/**
 * A frame still loading pulses: on a large video (4K, a high bitrate) each
 * one downloads a piece of the file, so the strip fills in over seconds.
 */
const frameClass = (index: number): string => {
    if (paintedFrames.value.includes(index - 1)) {
        return 'opacity-60';
    }

    return 'animate-pulse bg-muted-foreground/20';
};

onMounted(() => {
    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.preload = 'metadata';
    video.addEventListener('loadedmetadata', paintFrames, { once: true });
    video.src = props.src;
    stripVideo = video;
});

onBeforeUnmount(() => {
    if (filled(stripVideo)) {
        releaseVideo(stripVideo);
    }

    stripVideo = null;
});

watch(seconds, showSelectedFrame);
</script>

<template>
    <div class="space-y-2" data-testid="media-editor-cover-timeline">
        <div class="relative h-20 select-none">
            <div class="flex h-full overflow-hidden rounded-lg bg-muted">
                <canvas
                    v-for="index in FRAME_COUNT"
                    :key="index"
                    ref="frames"
                    class="h-full min-w-0 flex-1 object-cover"
                    :class="frameClass(index)"
                />
            </div>
            <div
                class="pointer-events-none absolute -inset-y-1.5 overflow-hidden rounded-lg ring-3 ring-primary-strong"
                :style="selectorStyle"
                data-testid="media-editor-cover-selector"
            >
                <video
                    ref="selectedVideo"
                    :src="src"
                    muted
                    playsinline
                    preload="metadata"
                    class="size-full bg-muted object-cover"
                    @loadedmetadata="showSelectedFrame"
                />
            </div>
            <input
                v-model.number="seconds"
                data-testid="media-editor-cover"
                type="range"
                min="0"
                :max="maxSeconds"
                step="0.1"
                :aria-label="$t('posts.composer.media_editor.thumbnail_tab')"
                :aria-valuetext="
                    $t('posts.composer.media_editor.thumbnail_value', {
                        seconds: seconds.toFixed(1),
                    })
                "
                class="absolute inset-0 size-full cursor-grab opacity-0 active:cursor-grabbing"
            />
        </div>
        <p
            class="text-center text-xs text-muted-foreground tabular-nums"
            data-testid="media-editor-cover-value"
        >
            {{ pickedTime }}
        </p>
    </div>
</template>
