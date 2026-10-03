<script setup lang="ts">
import {
    IconChevronLeft,
    IconChevronRight,
    IconPlayerPlayFilled,
    IconX,
    IconZoomIn,
    IconZoomOut,
} from '@tabler/icons-vue';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';

import { isImage, isVideo } from '@/lib/mediaType';
import { videoFrameUrl } from '@/lib/videoFrame';
import type { MediaItem } from '@/types/media';

type LightboxMedia = Pick<
    MediaItem,
    'url' | 'type' | 'mime_type' | 'original_filename' | 'path' | 'meta'
>;

const props = withDefaults(
    defineProps<{
        items: LightboxMedia[];
        startIndex?: number;
    }>(),
    { startIndex: 0 },
);

const open = defineModel<boolean>('open', { default: false });

const MIN_ZOOM = 1;
const MAX_ZOOM = 3;
const ZOOM_STEP = 0.5;

const visuals = computed(() =>
    props.items.filter((item) => isImage(item) || isVideo(item)),
);

const index = ref(0);
const zoom = ref(MIN_ZOOM);
const offset = ref({ x: 0, y: 0 });
const image = ref<HTMLImageElement | null>(null);
const stage = ref<HTMLElement | null>(null);

const current = computed(() => visuals.value[index.value] ?? null);
const hasMany = computed(() => visuals.value.length > 1);
const hasPrevious = computed(() => index.value > 0);
const hasNext = computed(() => index.value < visuals.value.length - 1);
const isZoomed = computed(() => zoom.value > MIN_ZOOM);

const resetZoom = (): void => {
    zoom.value = MIN_ZOOM;
    offset.value = { x: 0, y: 0 };
};

const visualIndexOf = (itemIndex: number): number => {
    const following = props.items
        .slice(Math.max(0, itemIndex))
        .find((item) => visuals.value.includes(item));

    return following ? visuals.value.indexOf(following) : 0;
};

watch(
    () => [open.value, props.startIndex] as const,
    ([isOpen, startIndex]) => {
        if (isOpen) {
            index.value = visualIndexOf(startIndex);
            resetZoom();
        }
    },
    { immediate: true },
);

watch(
    () => visuals.value.length,
    (length) => {
        if (open.value && length === 0) {
            open.value = false;
        }
    },
);

const goTo = (target: number): void => {
    if (target < 0 || target >= visuals.value.length) {
        return;
    }

    index.value = target;
    resetZoom();
};

const goPrevious = (): void => goTo(index.value - 1);

const goNext = (): void => goTo(index.value + 1);

const clampOffset = (x: number, y: number): { x: number; y: number } => {
    const element = image.value;

    if (!element || !stage.value || !isZoomed.value) {
        return { x: 0, y: 0 };
    }

    const maxX = Math.max(
        0,
        (element.offsetWidth * zoom.value - stage.value.clientWidth) / 2,
    );
    const maxY = Math.max(
        0,
        (element.offsetHeight * zoom.value - stage.value.clientHeight) / 2,
    );

    return {
        x: Math.min(maxX, Math.max(-maxX, x)),
        y: Math.min(maxY, Math.max(-maxY, y)),
    };
};

const setZoom = (value: number): void => {
    const next = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, value));
    const ratio = next / zoom.value;

    zoom.value = next;
    offset.value = clampOffset(offset.value.x * ratio, offset.value.y * ratio);
};

const zoomIn = (): void => setZoom(zoom.value + ZOOM_STEP);

const zoomOut = (): void => setZoom(zoom.value - ZOOM_STEP);

let drag: { pointerId: number; x: number; y: number } | null = null;
const dragging = ref(false);

const onPointerDown = (event: PointerEvent): void => {
    if (!isZoomed.value) {
        return;
    }

    event.preventDefault();
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    drag = {
        pointerId: event.pointerId,
        x: event.clientX - offset.value.x,
        y: event.clientY - offset.value.y,
    };
    dragging.value = true;
};

const onPointerMove = (event: PointerEvent): void => {
    if (!drag || drag.pointerId !== event.pointerId) {
        return;
    }

    offset.value = clampOffset(event.clientX - drag.x, event.clientY - drag.y);
};

const onPointerUp = (event: PointerEvent): void => {
    if (drag?.pointerId === event.pointerId) {
        drag = null;
        dragging.value = false;
    }
};

const onKeydown = (event: KeyboardEvent): void => {
    if (
        (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') ||
        event.target instanceof HTMLVideoElement
    ) {
        return;
    }

    event.preventDefault();

    const rtl =
        getComputedStyle(event.currentTarget as HTMLElement).direction ===
        'rtl';

    if ((event.key === 'ArrowRight') !== rtl) {
        goNext();
    } else {
        goPrevious();
    }
};

const close = (): void => {
    open.value = false;
};

const strip = ref<HTMLElement | null>(null);

watch(
    () => [open.value, index.value] as const,
    async ([isOpen, active]) => {
        if (!isOpen || !hasMany.value) {
            return;
        }

        await nextTick();
        strip.value?.children[active]?.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
        });
    },
);

const navButtonClass =
    'absolute top-1/2 z-10 inline-flex size-16 -translate-y-1/2 cursor-pointer items-center justify-center rounded-2xl bg-black/50 text-white backdrop-blur-sm transition hover:bg-black/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-black/50';

const toolButtonClass =
    'inline-flex size-9 cursor-pointer items-center justify-center rounded-full text-white transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-white disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent';
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="motion-dialog-overlay fixed inset-0 z-50 bg-black/80"
            />
            <DialogContent
                class="fixed inset-0 z-50 flex flex-col outline-none"
                data-testid="media-lightbox"
                :aria-describedby="undefined"
                @keydown="onKeydown"
            >
                <DialogTitle class="sr-only">
                    {{ $t('common.media_lightbox.title') }}
                </DialogTitle>

                <div
                    class="relative flex h-16 shrink-0 items-center justify-center px-4"
                >
                    <span
                        v-if="hasMany"
                        data-testid="media-lightbox-counter"
                        class="rounded-full bg-black/60 px-3 py-1 text-sm text-white tabular-nums"
                        aria-live="polite"
                    >
                        {{
                            $t('common.media_lightbox.counter', {
                                current: String(index + 1),
                                total: String(visuals.length),
                            })
                        }}
                    </span>
                    <DialogClose
                        :aria-label="$t('common.close')"
                        data-testid="media-lightbox-close"
                        class="absolute end-4 top-3 inline-flex size-10 cursor-pointer items-center justify-center rounded-full bg-white/15 text-white backdrop-blur-sm transition hover:bg-white/25 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    >
                        <IconX class="size-5" stroke-width="2.5" />
                    </DialogClose>
                </div>

                <div class="relative min-h-0 flex-1">
                    <div
                        ref="stage"
                        class="flex size-full items-center justify-center overflow-hidden px-4 sm:px-24"
                        data-testid="media-lightbox-stage"
                        @click.self="close"
                    >
                        <img
                            v-if="current && isImage(current)"
                            ref="image"
                            :key="current.url"
                            :src="current.url"
                            :alt="current.meta?.alt_text ?? ''"
                            :data-zoom="zoom"
                            data-testid="media-lightbox-image"
                            class="max-h-full max-w-full touch-none object-contain select-none"
                            :class="[
                                isZoomed
                                    ? 'cursor-grab active:cursor-grabbing'
                                    : '',
                                dragging
                                    ? ''
                                    : 'transition-transform duration-150',
                            ]"
                            :style="{
                                transform: `translate(${offset.x}px, ${offset.y}px) scale(${zoom})`,
                            }"
                            draggable="false"
                            @pointerdown="onPointerDown"
                            @pointermove="onPointerMove"
                            @pointerup="onPointerUp"
                            @pointercancel="onPointerUp"
                        />

                        <video
                            v-else-if="current && isVideo(current)"
                            :key="current.url"
                            :src="current.url"
                            data-testid="media-lightbox-video"
                            class="max-h-full max-w-full bg-black"
                            controls
                            autoplay
                            playsinline
                            preload="metadata"
                        />
                    </div>

                    <template v-if="hasMany">
                        <button
                            type="button"
                            :aria-label="$t('common.media_lightbox.previous')"
                            :disabled="!hasPrevious"
                            data-testid="media-lightbox-previous"
                            :class="[navButtonClass, 'start-4']"
                            @click="goPrevious"
                        >
                            <IconChevronLeft class="size-8 rtl:rotate-180" />
                        </button>
                        <button
                            type="button"
                            :aria-label="$t('common.media_lightbox.next')"
                            :disabled="!hasNext"
                            data-testid="media-lightbox-next"
                            :class="[navButtonClass, 'end-4']"
                            @click="goNext"
                        >
                            <IconChevronRight class="size-8 rtl:rotate-180" />
                        </button>
                    </template>
                </div>

                <div
                    class="flex shrink-0 flex-col items-center gap-3 px-4 pt-3 pb-4"
                >
                    <p
                        v-if="current?.meta?.alt_text"
                        data-testid="media-lightbox-alt-text"
                        class="max-w-2xl rounded-lg bg-black/70 px-4 py-2 text-center text-sm text-white backdrop-blur-sm"
                    >
                        {{ current.meta.alt_text }}
                    </p>
                    <div
                        v-if="current && isImage(current)"
                        class="flex items-center gap-1 rounded-full bg-black/60 p-1 text-white backdrop-blur-sm"
                    >
                        <button
                            type="button"
                            :aria-label="$t('common.media_lightbox.zoom_out')"
                            :disabled="!isZoomed"
                            data-testid="media-lightbox-zoom-out"
                            :class="toolButtonClass"
                            @click="zoomOut"
                        >
                            <IconZoomOut class="size-5" />
                        </button>
                        <button
                            type="button"
                            :aria-label="$t('common.media_lightbox.zoom_in')"
                            :disabled="zoom >= MAX_ZOOM"
                            data-testid="media-lightbox-zoom-in"
                            :class="toolButtonClass"
                            @click="zoomIn"
                        >
                            <IconZoomIn class="size-5" />
                        </button>
                    </div>
                    <div
                        v-if="hasMany"
                        ref="strip"
                        class="flex max-w-full gap-2 overflow-x-auto p-1"
                        data-testid="media-lightbox-thumbnails"
                    >
                        <button
                            v-for="(item, itemIndex) in visuals"
                            :key="item.url"
                            type="button"
                            :aria-label="
                                $t('common.media_lightbox.go_to', {
                                    number: String(itemIndex + 1),
                                })
                            "
                            :aria-current="itemIndex === index ? 'true' : undefined"
                            :data-active="itemIndex === index"
                            :data-testid="`media-lightbox-thumbnail-${itemIndex}`"
                            class="relative size-20 shrink-0 cursor-pointer overflow-hidden rounded-lg bg-white/10 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            :class="
                                itemIndex === index
                                    ? 'opacity-100 ring-2 ring-white'
                                    : 'opacity-50 hover:opacity-100'
                            "
                            @click="goTo(itemIndex)"
                        >
                            <template v-if="isVideo(item)">
                                <video
                                    :src="videoFrameUrl(item)"
                                    class="size-full object-cover"
                                    muted
                                    playsinline
                                    preload="metadata"
                                />
                                <IconPlayerPlayFilled
                                    aria-hidden="true"
                                    class="absolute top-1/2 left-1/2 size-6 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-1.5 text-white"
                                />
                            </template>
                            <img
                                v-else
                                :src="item.url"
                                alt=""
                                class="size-full object-cover"
                                draggable="false"
                            />
                        </button>
                    </div>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
