<script setup lang="ts">
import { IconUpload } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { filled } from '@/lib/helpers';
import { type CoverSource, type MediaEdit } from '@/lib/mediaEditor';
import { fromMimeType, MediaType, stillImageAcceptAttribute } from '@/lib/mediaType';

const COVER_SOURCES: CoverSource[] = ['frame', 'image'];

const props = defineProps<{
    supportsCustomCover: boolean;
}>();

const edit = defineModel<MediaEdit>('edit', { required: true });
const source = defineModel<CoverSource>('source', { required: true });

const fileInput = ref<HTMLInputElement | null>(null);

const showsImage = computed(() => {
    if (!props.supportsCustomCover) {
        return false;
    }

    return source.value === 'image';
});
const hasCoverImage = computed(() => filled(edit.value.coverImage));
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
        <template v-if="showsImage">
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
        </template>
    </div>
</template>
