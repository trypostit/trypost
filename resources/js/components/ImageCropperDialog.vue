<script setup lang="ts">
import { IconZoomIn, IconZoomOut } from '@tabler/icons-vue';
import { computed, markRaw, ref, watch } from 'vue';
import { CircleStencil, Cropper, RectangleStencil } from 'vue-advanced-cropper';
import 'vue-advanced-cropper/dist/style.css';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    open: boolean;
    /** Data URL of the selected image. */
    src: string | null;
    fileName?: string;
    mimeType?: string;
    /** Mask shape — round by default. */
    shape?: 'circle' | 'square';
};

const props = withDefaults(defineProps<Props>(), {
    fileName: 'image.png',
    mimeType: 'image/png',
    shape: 'circle',
});

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'cropped', file: File): void;
}>();

type CropperInstance = {
    getResult: () => { canvas?: HTMLCanvasElement | null };
    zoom: (factor: number) => void;
};

const cropperRef = ref<CropperInstance | null>(null);
const processing = ref(false);
// Cropper inside a modal: only mount it after the dialog has its final layout,
// otherwise it measures 0x0 and the circular stencil degrades into a square.
const cropperReady = ref(false);

// markRaw: components shouldn't become reactive when passed as a prop.
const stencilComponent = computed(() => markRaw(props.shape === 'square' ? RectangleStencil : CircleStencil));

const close = () => {
    emit('update:open', false);
};

const zoom = (factor: number) => {
    cropperRef.value?.zoom(factor);
};

const save = () => {
    const result = cropperRef.value?.getResult();
    const canvas = result?.canvas;
    if (!canvas) {
        return;
    }

    processing.value = true;
    canvas.toBlob(
        (blob) => {
            processing.value = false;
            if (!blob) {
                return;
            }
            const file = new File([blob], props.fileName, { type: props.mimeType });
            emit('cropped', file);
            close();
        },
        props.mimeType,
        0.92,
    );
};

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            // Wait two frames to make sure the DialogContent already has a size.
            cropperReady.value = false;
            requestAnimationFrame(() => requestAnimationFrame(() => {
                cropperReady.value = true;
            }));
        } else {
            cropperReady.value = false;
            processing.value = false;
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('common.photo_upload.crop_title') }}</DialogTitle>
                <DialogDescription>{{ $t('common.photo_upload.crop_description') }}</DialogDescription>
            </DialogHeader>

            <div class="overflow-hidden rounded-xl border-2 border-foreground bg-muted">
                <Cropper
                    v-if="src && cropperReady"
                    ref="cropperRef"
                    :src="src"
                    :stencil-component="stencilComponent"
                    :stencil-props="{ aspectRatio: 1 }"
                    :canvas="{ maxWidth: 512, maxHeight: 512 }"
                    image-restriction="stencil"
                    class="h-[340px]"
                />
                <div v-else class="h-[340px]" />
            </div>

            <div class="flex items-center justify-center gap-3">
                <Button type="button" variant="outline" size="icon" class="size-9" @click="zoom(0.9)">
                    <IconZoomOut class="size-4" />
                </Button>
                <span class="text-xs text-muted-foreground">{{ $t('common.photo_upload.crop_hint') }}</span>
                <Button type="button" variant="outline" size="icon" class="size-9" @click="zoom(1.1)">
                    <IconZoomIn class="size-4" />
                </Button>
            </div>

            <DialogFooter>
                <Button type="button" :disabled="processing || !src" @click="save">
                    {{ $t('common.photo_upload.crop_save') }}
                </Button>
                <Button type="button" variant="outline" @click="close">
                    {{ $t('common.photo_upload.crop_cancel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
