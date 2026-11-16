<script setup lang="ts">
import { IconUpload } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { filled } from '@/lib/helpers';
import { formatCoverOffset, type MediaEdit } from '@/lib/mediaEditor';
import { fromMimeType, MediaType, stillImageAcceptAttribute } from '@/lib/mediaType';
import { rangeFill } from '@/lib/rangeFill';

type CoverSource = 'frame' | 'image';

const COVER_SOURCES: CoverSource[] = ['frame', 'image'];

const props = defineProps<{
    duration: number | null;
    supportsCustomCover: boolean;
}>();

const edit = defineModel<MediaEdit>('edit', { required: true });

/** A cover image without a picked frame is one the user uploaded; a captured frame keeps its time. */
const initialSource = (): CoverSource => {
    if (!filled(edit.value.coverImage)) {
        return 'frame';
    }

    if (filled(edit.value.coverOffsetMs)) {
        return 'frame';
    }

    return 'image';
};

const source = ref<CoverSource>(initialSource());
const fileInput = ref<HTMLInputElement | null>(null);

const maxSeconds = computed(() =>
    Math.max(0, Math.floor((props.duration ?? 0) * 10) / 10),
);
const seconds = computed({
    get: () => (edit.value.coverOffsetMs ?? 0) / 1000,
    set: (value: number) => {
        edit.value.coverOffsetMs = Math.round(value * 1000);
    },
});
const showsImage = computed(() => {
    if (!props.supportsCustomCover) {
        return false;
    }

    return source.value === 'image';
});
const hasCoverImage = computed(() => filled(edit.value.coverImage));
const coverImageUrl = computed(() => edit.value.coverImage?.url);
const accept = stillImageAcceptAttribute();

const sourceClass = (option: CoverSource): string => {
    if (source.value === option) {
        return 'bg-primary-selected font-medium text-primary-text';
    }

    return 'text-muted-foreground hover:text-foreground';
};

const selectSource = (option: CoverSource): void => {
    source.value = option;

    if (option === 'image') {
        edit.value.coverOffsetMs = null;

        return;
    }

    edit.value.coverImage = null;
    edit.value.coverOffsetMs = 0;
};

const pickImage = (): void => {
    fileInput.value?.click();
};

const onImagePicked = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!filled(file)) {
        return;
    }

    if (fromMimeType(file.type) !== MediaType.Image) {
        return;
    }

    edit.value.coverImage = {
        url: URL.createObjectURL(file),
        item: null,
        file,
    };
};
</script>

<template>
    <div class="space-y-3">
        <div class="space-y-1">
            <h3 class="text-sm font-medium">
                {{ $t('posts.composer.media_editor.thumbnail_heading') }}
            </h3>
            <p v-if="showsImage" class="text-xs text-muted-foreground">
                {{ $t('posts.composer.media_editor.thumbnail_image_hint') }}
            </p>
            <p v-else class="text-xs text-muted-foreground">
                {{ $t('posts.composer.media_editor.thumbnail_hint') }}
            </p>
        </div>
        <div
            v-if="supportsCustomCover"
            role="group"
            data-testid="media-editor-cover-source"
            class="grid grid-cols-2 rounded-lg border border-border p-1"
        >
            <button
                v-for="option in COVER_SOURCES"
                :key="option"
                type="button"
                :aria-pressed="source === option"
                :data-testid="`media-editor-cover-source-${option}`"
                class="rounded-md px-2 py-1.5 text-sm whitespace-nowrap transition-colors"
                :class="sourceClass(option)"
                @click="selectSource(option)"
            >
                {{ $t(`posts.composer.media_editor.thumbnail_source_${option}`) }}
            </button>
        </div>
        <div v-if="showsImage" class="space-y-3">
            <img
                v-if="hasCoverImage"
                :src="coverImageUrl"
                :alt="$t('posts.composer.media_editor.thumbnail_image_alt')"
                data-testid="media-editor-cover-image"
                class="aspect-9/16 w-32 rounded-lg bg-muted object-cover"
            />
            <input
                ref="fileInput"
                type="file"
                class="hidden"
                :accept="accept"
                data-testid="media-editor-cover-image-input"
                @change="onImagePicked"
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-testid="media-editor-cover-image-pick"
                @click="pickImage"
            >
                <IconUpload class="size-4" />
                <span v-if="hasCoverImage">{{
                    $t('posts.composer.media_editor.thumbnail_image_replace')
                }}</span>
                <span v-else>{{
                    $t('posts.composer.media_editor.thumbnail_image_upload')
                }}</span>
            </Button>
        </div>
        <label v-else class="block space-y-1 text-sm">
            <span class="flex justify-between">
                <span>{{
                    $t('posts.composer.media_editor.thumbnail_tab')
                }}</span>
                <span
                    class="text-muted-foreground tabular-nums"
                    data-testid="media-editor-cover-value"
                    >{{ formatCoverOffset(edit.coverOffsetMs ?? 0) }}</span
                >
            </span>
            <input
                v-model.number="seconds"
                data-testid="media-editor-cover"
                :style="rangeFill(seconds, 0, maxSeconds)"
                type="range"
                min="0"
                :max="maxSeconds"
                step="0.1"
                :aria-valuetext="
                    $t('posts.composer.media_editor.thumbnail_value', {
                        seconds: seconds.toFixed(1),
                    })
                "
                class="editor-range w-full"
            />
        </label>
    </div>
</template>
