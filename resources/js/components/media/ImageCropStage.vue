<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    toRef,
    type HTMLAttributes,
} from 'vue';

import { type ImageSize, useImageCrop } from '@/composables/useImageCrop';
import {
    clampSelection,
    containScale,
    type Corner,
    resizeSelection,
    type SourceRect,
} from '@/lib/imageCrop';
import {
    cssFilter,
    imageTransform,
    type MediaEdit,
    warmthOverlay,
} from '@/lib/mediaEditor';

const props = withDefaults(
    defineProps<{
        src: string;
        natural: ImageSize | null;
        cropMode?: boolean;
        padding?: number;
        testIdPrefix: string;
        boxClass?: HTMLAttributes['class'];
    }>(),
    {
        cropMode: true,
        padding: 48,
        boxClass: undefined,
    },
);

const edit = defineModel<MediaEdit>('edit', { required: true });

const emit = defineEmits<{
    (e: 'load', size: ImageSize): void;
    (e: 'error'): void;
    (e: 'press', point: { x: number; y: number }): void;
}>();

const HANDLES: Array<{ corner: Corner; class: string }> = [
    { corner: 'nw', class: 'top-0 left-0 cursor-nwse-resize' },
    { corner: 'ne', class: 'top-0 right-0 cursor-nesw-resize' },
    { corner: 'sw', class: 'bottom-0 left-0 cursor-nesw-resize' },
    { corner: 'se', class: 'right-0 bottom-0 cursor-nwse-resize' },
];

const rootEl = ref<HTMLElement | null>(null);
const boxEl = ref<HTMLElement | null>(null);
const stageSize = ref<ImageSize>({ width: 0, height: 0 });

let resizeObserver: ResizeObserver | null = null;
let drag: {
    mode: 'move' | Corner;
    pointerId: number;
    clientX: number;
    clientY: number;
    selection: SourceRect;
} | null = null;

const natural = toRef(props, 'natural');
const { size, crop, ratio, minSize } = useImageCrop(edit, natural);

const frame = computed<SourceRect>(() =>
    props.cropMode
        ? { sx: 0, sy: 0, sw: size.value.width, sh: size.value.height }
        : crop.value,
);
const ready = computed(
    () =>
        props.natural !== null &&
        frame.value.sw > 0 &&
        stageSize.value.width > 0,
);
const scale = computed(() =>
    containScale(
        frame.value.sw,
        frame.value.sh,
        stageSize.value.width,
        stageSize.value.height,
    ),
);
const px = (value: number): string => `${value * scale.value}px`;

const boxStyle = computed(() => ({
    width: px(frame.value.sw),
    height: px(frame.value.sh),
}));
const layerStyle = computed(() => ({
    left: px(-frame.value.sx),
    top: px(-frame.value.sy),
    width: px(size.value.width),
    height: px(size.value.height),
}));
const imageStyle = computed(() => {
    if (!props.natural) return {};
    const transform = imageTransform(props.natural, edit.value);

    return {
        width: px(props.natural.width),
        height: px(props.natural.height),
        transform: `translate(-50%, -50%) rotate(${transform.rotation}rad) scale(${transform.scaleX}, ${transform.scaleY})`,
        filter: cssFilter(edit.value),
    };
});
const overlayColor = computed(() => warmthOverlay(edit.value));
const selectionStyle = computed(() => ({
    left: px(crop.value.sx),
    top: px(crop.value.sy),
    width: px(crop.value.sw),
    height: px(crop.value.sh),
    boxShadow: '0 0 0 9999px rgba(0, 0, 0, 0.5)',
}));

const measure = (): void => {
    const el = rootEl.value;
    if (!el) return;
    stageSize.value = {
        width: Math.max(0, el.clientWidth - props.padding),
        height: Math.max(0, el.clientHeight - props.padding),
    };
};

const onImageLoad = (event: Event): void => {
    const image = event.target as HTMLImageElement;
    if (image.naturalWidth === 0 || image.naturalHeight === 0) {
        emit('error');
        return;
    }
    emit('load', { width: image.naturalWidth, height: image.naturalHeight });
};

const beginDrag = (mode: 'move' | Corner, event: PointerEvent): void => {
    if (!ready.value || drag) return;
    drag = {
        mode,
        pointerId: event.pointerId,
        clientX: event.clientX,
        clientY: event.clientY,
        selection: { ...crop.value },
    };
    boxEl.value?.setPointerCapture(event.pointerId);
};

const onPointerMove = (event: PointerEvent): void => {
    if (!drag || event.pointerId !== drag.pointerId) return;

    if (drag.mode === 'move') {
        edit.value.selection = clampSelection(
            {
                ...drag.selection,
                sx:
                    drag.selection.sx +
                    (event.clientX - drag.clientX) / scale.value,
                sy:
                    drag.selection.sy +
                    (event.clientY - drag.clientY) / scale.value,
            },
            size.value.width,
            size.value.height,
            minSize.value,
            ratio.value,
        );
        return;
    }

    const box = boxEl.value!.getBoundingClientRect();
    edit.value.selection = resizeSelection(
        drag.selection,
        drag.mode,
        (event.clientX - box.left) / scale.value,
        (event.clientY - box.top) / scale.value,
        size.value.width,
        size.value.height,
        minSize.value,
        ratio.value,
    );
};

const onPointerUp = (event: PointerEvent): void => {
    if (!drag || event.pointerId !== drag.pointerId) return;
    if (boxEl.value?.hasPointerCapture(event.pointerId)) {
        boxEl.value.releasePointerCapture(event.pointerId);
    }
    drag = null;
};

const onClick = (event: MouseEvent): void => {
    if (!boxEl.value) return;
    const box = boxEl.value.getBoundingClientRect();
    emit('press', {
        x: (event.clientX - box.left) / box.width,
        y: (event.clientY - box.top) / box.height,
    });
};

onMounted(() => {
    measure();
    if (rootEl.value) {
        resizeObserver = new ResizeObserver(measure);
        resizeObserver.observe(rootEl.value);
    }
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;
    drag = null;
});
</script>

<template>
    <div
        ref="rootEl"
        class="absolute inset-0 flex items-center justify-center overflow-hidden"
    >
        <div
            ref="boxEl"
            :data-testid="`${testIdPrefix}-stage`"
            class="relative touch-none"
            :class="[ready ? '' : 'invisible', boxClass]"
            :style="boxStyle"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @click="onClick"
        >
            <div
                class="absolute overflow-hidden"
                :class="cropMode ? '' : 'pointer-events-none'"
                :style="layerStyle"
            >
                <img
                    :key="src"
                    :src="src"
                    alt=""
                    draggable="false"
                    class="pointer-events-none absolute top-1/2 left-1/2 max-w-none"
                    :style="imageStyle"
                    @load="onImageLoad"
                    @error="emit('error')"
                />
            </div>
            <div
                v-if="overlayColor"
                class="pointer-events-none absolute inset-0"
                :style="{ backgroundColor: overlayColor }"
            />
            <div
                v-if="cropMode && ready"
                class="absolute cursor-move"
                :data-testid="`${testIdPrefix}-selection`"
                :style="selectionStyle"
                @pointerdown="beginDrag('move', $event)"
            >
                <div
                    class="pointer-events-none absolute inset-0 overflow-hidden border border-white"
                >
                    <span class="absolute inset-y-0 left-1/3 w-px bg-white/40" />
                    <span class="absolute inset-y-0 left-2/3 w-px bg-white/40" />
                    <span class="absolute inset-x-0 top-1/3 h-px bg-white/40" />
                    <span class="absolute inset-x-0 top-2/3 h-px bg-white/40" />
                </div>
                <span
                    v-for="handle in HANDLES"
                    :key="handle.corner"
                    :data-testid="`${testIdPrefix}-handle-${handle.corner}`"
                    class="absolute size-3 rounded-full border border-foreground bg-white"
                    :class="handle.class"
                    @pointerdown.stop="beginDrag(handle.corner, $event)"
                />
            </div>
            <slot />
        </div>
    </div>
</template>
