<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    toRef,
    type HTMLAttributes,
} from 'vue';

import { useImageCrop } from '@/composables/app/useImageCrop';
import {
    clampSelection,
    containScale,
    resizeSelection,
} from '@/lib/app/imageCrop';
import {
    cssFilter,
    imageTransform,
    warmthOverlay,
} from '@/lib/app/mediaEditor';
import { CropPreset } from '@/types/app/crop-preset';
import type { Corner, ImageSize, SourceRect } from '@/types/app/image-crop';
import type { MediaEdit } from '@/types/app/media-editor';

const props = withDefaults(
    defineProps<{
        src: string;
        natural: ImageSize | null;
        cropMode?: boolean;
        padding?: number;
        testIdPrefix: string;
        boxClass?: HTMLAttributes['class'];
        /** Lets a fixed-ratio crop change size too (the profile photo's square). */
        resizeAnyRatio?: boolean;
    }>(),
    {
        cropMode: true,
        resizeAnyRatio: false,
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

const HANDLES: Array<{ corner: Corner; x: 'left' | 'right'; y: 'top' | 'bottom'; cursor: string }> = [
    { corner: 'nw', x: 'left', y: 'top', cursor: 'cursor-nwse-resize' },
    { corner: 'ne', x: 'right', y: 'top', cursor: 'cursor-nesw-resize' },
    { corner: 'sw', x: 'left', y: 'bottom', cursor: 'cursor-nesw-resize' },
    { corner: 'se', x: 'right', y: 'bottom', cursor: 'cursor-nwse-resize' },
];

/** The corner and edge marks show on a crop being framed, never on the Original (uncropped) image. */
const showsCropMarks = computed(
    () => edit.value.preset !== CropPreset.Original,
);

const FRAME_DARK = '#121414';
const MARK_OUTLINE = '#d1d1d1';

type Box = Record<string, string>;

/**
 * The marks drawn around a cropped frame, measured from the crop edge: an
 * outline layer first and the white layer on top, so the corner of an L has no
 * seam. Corner L: 4px thick, 15px arms, 1px light outline, 3px out of the edge
 * (covering the outer frame line). Edge tabs: 20 by 4px, centred.
 */
const markLayers = computed((): Array<{ style: Box; class: string }> => {
    const layers: Array<{ style: Box; class: string }> = [];
    const outline = (box: Box): void => {
        layers.push({ style: box, class: 'rounded-[3px]' });
    };

    HANDLES.forEach(({ x, y }) => {
        outline({ [x]: '-5px', [y]: '-5px', width: '17px', height: '6px', backgroundColor: MARK_OUTLINE });
        outline({ [x]: '-5px', [y]: '-5px', width: '6px', height: '17px', backgroundColor: MARK_OUTLINE });
    });
    (['top', 'bottom'] as const).forEach((side) =>
        outline({ [side]: '-5px', left: 'calc(50% - 11px)', width: '22px', height: '6px', backgroundColor: MARK_OUTLINE }),
    );
    (['left', 'right'] as const).forEach((side) =>
        outline({ [side]: '-5px', top: 'calc(50% - 11px)', width: '6px', height: '22px', backgroundColor: MARK_OUTLINE }),
    );

    const fill = (box: Box): void => {
        layers.push({ style: { ...box, backgroundColor: '#ffffff' }, class: 'rounded-[2px]' });
    };

    HANDLES.forEach(({ x, y }) => {
        fill({ [x]: '-4px', [y]: '-4px', width: '15px', height: '4px' });
        fill({ [x]: '-4px', [y]: '-4px', width: '4px', height: '15px' });
    });
    (['top', 'bottom'] as const).forEach((side) =>
        fill({ [side]: '-4px', left: 'calc(50% - 10px)', width: '20px', height: '4px' }),
    );
    (['left', 'right'] as const).forEach((side) =>
        fill({ [side]: '-4px', top: 'calc(50% - 10px)', width: '4px', height: '20px' }),
    );

    return layers;
});

/** The crop edge line and a second line 3px outside it, white between them. */
const frameStyle = {
    boxShadow: `inset 0 0 0 1px ${FRAME_DARK}, 0 0 0 2px #ffffff, 0 0 0 3px ${FRAME_DARK}`,
};

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
}));

/** Only a freeform crop changes size; a chosen ratio keeps it and only moves. */
const resizable = computed(
    () => props.resizeAnyRatio || edit.value.preset === CropPreset.Freeform,
);

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
    if (!ready.value || drag || (mode !== 'move' && !resizable.value)) return;
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
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 overflow-hidden"
            >
                <div
                    class="absolute shadow-[0_0_0_9999px_color-mix(in_oklab,var(--color-background)_60%,transparent)]"
                    :data-testid="`${testIdPrefix}-veil`"
                    :style="selectionStyle"
                />
            </div>
            <div
                v-if="cropMode && ready"
                class="absolute cursor-move"
                :data-testid="`${testIdPrefix}-selection`"
                :style="selectionStyle"
                @pointerdown="beginDrag('move', $event)"
            >
                <div
                    class="pointer-events-none absolute inset-0"
                    :style="frameStyle"
                    :data-testid="`${testIdPrefix}-frame`"
                />
                <template v-if="showsCropMarks">
                    <span
                        v-for="(layer, index) in markLayers"
                        :key="index"
                        aria-hidden="true"
                        class="pointer-events-none absolute"
                        :class="layer.class"
                        :style="layer.style"
                    />
                    <template v-if="resizable">
                        <span
                            v-for="handle in HANDLES"
                            :key="handle.corner"
                            :data-testid="`${testIdPrefix}-handle-${handle.corner}`"
                            class="absolute size-5"
                            :class="handle.cursor"
                            :style="{ [handle.x]: '-8px', [handle.y]: '-8px' }"
                            @pointerdown.stop="beginDrag(handle.corner, $event)"
                        />
                    </template>
                </template>
            </div>
            <slot />
        </div>
    </div>
</template>
