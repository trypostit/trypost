<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    clampSelection,
    containScale,
    type Corner,
    defaultSelection,
    resizeSelection,
    resolveOutputFileName,
    resolveOutputMime,
    type SourceRect,
} from '@/lib/imageCrop';

type Props = {
    open: boolean;
    src: string | null;
    fileName?: string;
    mimeType?: string;
    outputSize?: number;
};

const props = withDefaults(defineProps<Props>(), {
    fileName: 'image.png',
    mimeType: 'image/png',
    outputSize: 512,
});

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'cropped', file: File): void;
}>();

const viewportEl = ref<HTMLElement | null>(null);
const imageEl = ref<HTMLImageElement | null>(null);
const viewportSize = ref(0);
const natural = ref({ width: 0, height: 0 });
const selection = ref<SourceRect>({ sx: 0, sy: 0, sw: 0, sh: 0 });
const processing = ref(false);
const initialized = ref(false);
const imageError = ref(false);

const MIN_SELECTION_RATIO = 0.1;

let dragMode: 'move' | Corner | null = null;
let activePointerId: number | null = null;
let dragStart = { pointerX: 0, pointerY: 0, selection: { sx: 0, sy: 0, sw: 0, sh: 0 } as SourceRect };
let resizeObserver: ResizeObserver | null = null;

const ready = computed(() => viewportSize.value > 0 && natural.value.width > 0);

const scale = computed(() => containScale(natural.value.width, natural.value.height, viewportSize.value));

const minSourceSize = computed(() => Math.min(natural.value.width, natural.value.height) * MIN_SELECTION_RATIO);

const imageDisplay = computed(() => {
    const width = natural.value.width * scale.value;
    const height = natural.value.height * scale.value;

    return {
        width,
        height,
        left: (viewportSize.value - width) / 2,
        top: (viewportSize.value - height) / 2,
    };
});

const imageStyle = computed(() => ({
    left: `${imageDisplay.value.left}px`,
    top: `${imageDisplay.value.top}px`,
    width: `${imageDisplay.value.width}px`,
    height: `${imageDisplay.value.height}px`,
}));

const selectionStyle = computed(() => ({
    left: `${imageDisplay.value.left + selection.value.sx * scale.value}px`,
    top: `${imageDisplay.value.top + selection.value.sy * scale.value}px`,
    width: `${selection.value.sw * scale.value}px`,
    height: `${selection.value.sh * scale.value}px`,
    boxShadow: '0 0 0 9999px rgba(0, 0, 0, 0.5)',
}));

const outputMime = computed(() => resolveOutputMime(props.mimeType));

const outputFileName = computed(() => resolveOutputFileName(props.fileName, outputMime.value));

const maybeInitialize = () => {
    if (!ready.value || initialized.value) {
        return;
    }

    selection.value = defaultSelection(natural.value.width, natural.value.height);
    initialized.value = true;
};

const measure = () => {
    const el = viewportEl.value;

    if (!el) {
        return;
    }

    viewportSize.value = el.clientWidth;
    maybeInitialize();
};

const onImageLoad = () => {
    const img = imageEl.value;

    if (!img) {
        return;
    }

    if (img.naturalWidth === 0 || img.naturalHeight === 0) {
        imageError.value = true;

        return;
    }

    natural.value = { width: img.naturalWidth, height: img.naturalHeight };
    maybeInitialize();
};

const onImageError = () => {
    imageError.value = true;
};

const sourcePoint = (clientX: number, clientY: number): { px: number; py: number } => {
    const box = viewportEl.value!.getBoundingClientRect();

    return {
        px: (clientX - box.left - imageDisplay.value.left) / scale.value,
        py: (clientY - box.top - imageDisplay.value.top) / scale.value,
    };
};

const beginDrag = (mode: 'move' | Corner, event: PointerEvent) => {
    if (!ready.value || dragMode) {
        return;
    }

    dragMode = mode;
    activePointerId = event.pointerId;
    dragStart = { pointerX: event.clientX, pointerY: event.clientY, selection: { ...selection.value } };
    viewportEl.value?.setPointerCapture(event.pointerId);
};

const endDrag = (event: PointerEvent) => {
    dragMode = null;
    activePointerId = null;

    if (viewportEl.value?.hasPointerCapture(event.pointerId)) {
        viewportEl.value.releasePointerCapture(event.pointerId);
    }
};

const onSelectionPointerDown = (event: PointerEvent) => {
    beginDrag('move', event);
};

const onHandlePointerDown = (corner: Corner, event: PointerEvent) => {
    beginDrag(corner, event);
};

const onPointerMove = (event: PointerEvent) => {
    if (!dragMode || event.pointerId !== activePointerId) {
        return;
    }

    if (dragMode === 'move') {
        selection.value = clampSelection(
            {
                sx: dragStart.selection.sx + (event.clientX - dragStart.pointerX) / scale.value,
                sy: dragStart.selection.sy + (event.clientY - dragStart.pointerY) / scale.value,
                sw: dragStart.selection.sw,
                sh: dragStart.selection.sh,
            },
            natural.value.width,
            natural.value.height,
            minSourceSize.value,
        );

        return;
    }

    const { px, py } = sourcePoint(event.clientX, event.clientY);
    selection.value = resizeSelection(
        dragStart.selection,
        dragMode,
        px,
        py,
        natural.value.width,
        natural.value.height,
        minSourceSize.value,
    );
};

const onPointerUp = (event: PointerEvent) => {
    if (!dragMode || event.pointerId !== activePointerId) {
        return;
    }

    endDrag(event);
};

const close = () => {
    emit('update:open', false);
};

const save = () => {
    const img = imageEl.value;

    if (!img || !ready.value) {
        return;
    }

    const canvas = document.createElement('canvas');
    canvas.width = props.outputSize;
    canvas.height = props.outputSize;

    const context = canvas.getContext('2d');

    if (!context) {
        return;
    }

    const rect = selection.value;
    processing.value = true;

    try {
        context.drawImage(img, rect.sx, rect.sy, rect.sw, rect.sh, 0, 0, props.outputSize, props.outputSize);
        canvas.toBlob(
            (blob) => {
                processing.value = false;

                if (!blob) {
                    return;
                }

                emit('cropped', new File([blob], outputFileName.value, { type: outputMime.value }));
                close();
            },
            outputMime.value,
            0.92,
        );
    } catch {
        processing.value = false;
    }
};

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            initialized.value = false;
            processing.value = false;
            imageError.value = false;
            dragMode = null;
            activePointerId = null;
            await nextTick();
            measure();

            if (viewportEl.value && !resizeObserver) {
                resizeObserver = new ResizeObserver(() => measure());
                resizeObserver.observe(viewportEl.value);
            }
        } else {
            dragMode = null;
            activePointerId = null;
            resizeObserver?.disconnect();
            resizeObserver = null;
        }
    },
);

watch(
    () => props.src,
    () => {
        initialized.value = false;
        imageError.value = false;
        natural.value = { width: 0, height: 0 };
    },
);

onBeforeUnmount(() => resizeObserver?.disconnect());
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('common.photo_upload.crop_title') }}</DialogTitle>
                <DialogDescription>{{ $t('common.photo_upload.crop_description') }}</DialogDescription>
            </DialogHeader>

            <div
                ref="viewportEl"
                class="relative aspect-square w-full touch-none select-none overflow-hidden rounded-xl border-2 border-foreground bg-muted"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
                @wheel.prevent
            >
                <img
                    v-if="src && !imageError"
                    ref="imageEl"
                    :src="src"
                    alt=""
                    draggable="false"
                    class="pointer-events-none absolute max-w-none"
                    :style="imageStyle"
                    @load="onImageLoad"
                    @error="onImageError"
                />
                <div
                    v-if="imageError"
                    class="absolute inset-0 flex items-center justify-center p-4 text-center text-sm text-muted-foreground"
                >
                    {{ $t('common.photo_upload.crop_error') }}
                </div>
                <div
                    v-else-if="ready"
                    class="absolute cursor-move"
                    :style="selectionStyle"
                    @pointerdown="onSelectionPointerDown"
                >
                    <div class="pointer-events-none absolute inset-0 border-2 border-white shadow-[0_0_0_1px_rgba(0,0,0,0.4)]" />
                    <span
                        class="absolute left-0 top-0 size-3 cursor-nwse-resize rounded-sm border border-foreground bg-white"
                        @pointerdown.stop="onHandlePointerDown('nw', $event)"
                    />
                    <span
                        class="absolute right-0 top-0 size-3 cursor-nesw-resize rounded-sm border border-foreground bg-white"
                        @pointerdown.stop="onHandlePointerDown('ne', $event)"
                    />
                    <span
                        class="absolute bottom-0 left-0 size-3 cursor-nesw-resize rounded-sm border border-foreground bg-white"
                        @pointerdown.stop="onHandlePointerDown('sw', $event)"
                    />
                    <span
                        class="absolute bottom-0 right-0 size-3 cursor-nwse-resize rounded-sm border border-foreground bg-white"
                        @pointerdown.stop="onHandlePointerDown('se', $event)"
                    />
                </div>
            </div>

            <p class="text-center text-xs text-muted-foreground">{{ $t('common.photo_upload.crop_hint') }}</p>

            <DialogFooter>
                <Button type="button" data-testid="crop-save" :disabled="processing || !ready" @click="save">
                    {{ $t('common.photo_upload.crop_save') }}
                </Button>
                <Button type="button" variant="outline" @click="close">
                    {{ $t('common.photo_upload.crop_cancel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
