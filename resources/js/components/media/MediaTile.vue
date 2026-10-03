<script setup lang="ts">
import {
    IconAlertTriangle,
    IconFileTypePdf,
    IconGripHorizontal,
    IconPalette,
    IconPencil,
    IconPlayerPlayFilled,
    IconUserPlus,
    IconX,
} from '@tabler/icons-vue';
import { computed, onBeforeUnmount } from 'vue';

import { isRetryable, type UploadEntry } from '@/composables/useMediaUpload';
import type { EditorTab } from '@/lib/mediaEditor';
import { isImage, isVideo } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    testIdPrefix: string;
    index: number;
    item?: MediaItem;
    upload?: UploadEntry;
    tabs?: EditorTab[];
    disabled?: boolean;
    error?: string;
    movable?: boolean;
    reorderHintId?: string;
    canvaEditable?: boolean;
}>();

const emit = defineEmits<{
    (event: 'remove'): void;
    (event: 'edit', payload: { index: number; tab: EditorTab }): void;
    (event: 'cancel'): void;
    (event: 'retry'): void;
    (event: 'move', offset: -1 | 1): void;
    (event: 'edit-canva'): void;
}>();

const uploadedFile = props.upload
    ? { mime_type: props.upload.file.type, original_filename: props.upload.file.name }
    : null;
const previewIsImage = isImage(uploadedFile);
const previewIsVideo = isVideo(uploadedFile);
const previewUrl =
    props.upload && (previewIsImage || previewIsVideo)
        ? URL.createObjectURL(props.upload.file)
        : null;

onBeforeUnmount(() => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
});

const onHandleKeydown = (event: KeyboardEvent): void => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    const rtl =
        getComputedStyle(event.currentTarget as HTMLElement).direction ===
        'rtl';
    emit('move', (event.key === 'ArrowRight') !== rtl ? 1 : -1);
};

const limitInMb = computed(() =>
    props.upload?.state === 'error' && props.upload.limitBytes
        ? String(Math.round(props.upload.limitBytes / 1024 / 1024))
        : '',
);

const editTab = computed<EditorTab | null>(() =>
    props.tabs?.includes('edit')
        ? 'edit'
        : props.tabs?.includes('thumbnail')
          ? 'thumbnail'
          : null,
);
const editButtonCount = computed(
    () =>
        Number(Boolean(props.tabs?.includes('tags'))) +
        Number(Boolean(props.tabs?.includes('alt'))) +
        Number(editTab.value !== null) +
        Number(Boolean(props.canvaEditable)),
);
const openEditor = (tab: EditorTab): void =>
    emit('edit', { index: props.index, tab });

const revealClass =
    'opacity-0 transition-opacity group-hover/tile:opacity-100 group-focus-within/tile:opacity-100 [@media(hover:none)]:opacity-100';

const actionShapeClass =
    'flex size-7 items-center justify-center rounded-md transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:opacity-50';
const darkColorClass = 'bg-black/80 text-white hover:bg-black/90';
const actionClass = `${actionShapeClass} ${darkColorClass}`;
const uploadActionClass =
    'flex size-6 items-center justify-center rounded-md border border-border bg-background/90 text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring';
</script>

<template>
    <div
        v-if="item"
        :data-testid="`${testIdPrefix}-media-item`"
        class="group/tile relative size-30 shrink-0 overflow-hidden rounded-lg border"
        :class="{ 'border-destructive': error }"
    >
        <img
            v-if="isImage(item)"
            :src="item.url"
            alt=""
            draggable="false"
            class="size-full object-cover"
        />
        <template v-else-if="isVideo(item)">
            <video
                :src="item.url"
                class="size-full object-cover"
                muted
                playsinline
                preload="metadata"
                draggable="false"
            />
            <IconPlayerPlayFilled
                aria-hidden="true"
                class="absolute top-1/2 left-1/2 size-8 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white"
            />
        </template>
        <span
            v-else
            class="flex size-full flex-col items-center justify-center gap-1 p-2 text-center text-xs break-all text-muted-foreground"
        >
            <IconFileTypePdf class="size-6" stroke-width="1.5" />
            <span class="line-clamp-2">{{ item.original_filename }}</span>
        </span>
        <p
            v-if="error"
            role="alert"
            :data-testid="`${testIdPrefix}-media-error-${index}`"
            :title="error"
            class="absolute inset-x-0 bottom-0 line-clamp-3 bg-destructive px-1.5 py-1 text-[11px] leading-tight text-destructive-foreground"
            :style="
                editButtonCount
                    ? { paddingInlineEnd: `${editButtonCount * 32 + 4}px` }
                    : undefined
            "
        >
            {{ error }}
        </p>
        <button
            v-show="movable"
            type="button"
            :class="[
                'absolute start-1/2 top-1 -translate-x-1/2 cursor-grab active:cursor-grabbing rtl:translate-x-1/2',
                revealClass,
                actionClass,
            ]"
            data-media-handle
            :data-testid="`${testIdPrefix}-drag-handle-${index}`"
            :aria-label="
                $t('posts.composer.media_reorder_handle', {
                    name: item.original_filename ?? '',
                })
            "
            :title="
                $t('posts.composer.media_reorder_handle', {
                    name: item.original_filename ?? '',
                })
            "
            :aria-describedby="reorderHintId"
            :disabled="disabled"
            @keydown="onHandleKeydown"
        >
            <IconGripHorizontal class="size-4" />
        </button>
        <button
            type="button"
            :class="['absolute end-1 top-1', revealClass, actionClass]"
            :data-testid="`${testIdPrefix}-remove-${index}`"
            :aria-label="
                $t('posts.composer.media_remove', {
                    name: item.original_filename ?? '',
                })
            "
            :disabled="disabled"
            @click="emit('remove')"
        >
            <IconX class="size-4" />
        </button>
        <span
            v-if="editButtonCount"
            :class="['absolute end-1 bottom-1 flex gap-1', revealClass]"
        >
            <button
                v-if="canvaEditable"
                type="button"
                :class="actionClass"
                :data-testid="`${testIdPrefix}-edit-canva-${index}`"
                :aria-label="
                    $t('posts.composer.media_sources.edit_in_canva', {
                        name: item.original_filename ?? '',
                    })
                "
                :disabled="disabled"
                @click="emit('edit-canva')"
            >
                <IconPalette class="size-4" />
            </button>
            <button
                v-if="tabs?.includes('tags')"
                type="button"
                :class="actionClass"
                :data-testid="`${testIdPrefix}-tag-${index}`"
                :aria-label="
                    $t('posts.composer.media_tag_people', {
                        name: item.original_filename ?? '',
                    })
                "
                :disabled="disabled"
                @click="openEditor('tags')"
            >
                <IconUserPlus class="size-4" />
            </button>
            <button
                v-if="tabs?.includes('alt')"
                type="button"
                :class="[
                    actionShapeClass,
                    'text-[10px] font-semibold',
                    item.meta?.alt_text
                        ? 'bg-primary text-primary-foreground hover:bg-primary-hover'
                        : darkColorClass,
                ]"
                :data-testid="`${testIdPrefix}-alt-${index}`"
                :aria-label="
                    $t('posts.composer.media_alt', {
                        name: item.original_filename ?? '',
                    })
                "
                :disabled="disabled"
                @click="openEditor('alt')"
            >
                ALT
            </button>
            <button
                v-if="editTab"
                type="button"
                :class="actionClass"
                :data-testid="`${testIdPrefix}-edit-${index}`"
                :aria-label="
                    $t('posts.composer.media_edit', {
                        name: item.original_filename ?? '',
                    })
                "
                :disabled="disabled"
                @click="openEditor(editTab)"
            >
                <IconPencil class="size-4" />
            </button>
        </span>
    </div>

    <div
        v-else-if="upload?.state === 'uploading'"
        :data-testid="`${testIdPrefix}-upload-item`"
        class="relative size-30 shrink-0 overflow-hidden rounded-lg border bg-muted"
    >
        <img
            v-if="previewUrl && previewIsImage"
            :src="previewUrl"
            alt=""
            class="size-full object-cover opacity-60"
        />
        <video
            v-else-if="previewUrl && previewIsVideo"
            :src="previewUrl"
            class="size-full object-cover opacity-60"
            muted
            playsinline
            preload="metadata"
        />
        <span
            v-else
            class="flex size-full items-center justify-center p-2 text-center text-xs break-all text-muted-foreground"
            >{{ upload.file.name }}</span
        >
        <button
            type="button"
            :class="['absolute end-1.5 top-1.5', uploadActionClass]"
            :data-testid="`${testIdPrefix}-upload-cancel-${index}`"
            :aria-label="$t('posts.composer.upload_cancel')"
            @click="emit('cancel')"
        >
            <IconX class="size-3.5" />
        </button>
        <div
            role="progressbar"
            :aria-label="upload.file.name"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="upload.progress"
            class="absolute inset-x-2 bottom-2 h-1 overflow-hidden rounded-full bg-background/70"
        >
            <div
                class="h-full rounded-full bg-primary transition-[width] duration-200"
                :style="{ width: `${Math.max(upload.progress, 4)}%` }"
            />
        </div>
    </div>

    <div
        v-else-if="upload?.state === 'error'"
        :data-testid="`${testIdPrefix}-upload-error`"
        :data-reason="upload.reason"
        role="alert"
        class="flex size-30 shrink-0 flex-col gap-1 overflow-hidden rounded-lg border border-destructive/60 bg-destructive/5 p-2 text-xs"
    >
        <span class="flex min-w-0 items-center gap-1 font-medium">
            <IconAlertTriangle
                aria-hidden="true"
                class="size-3.5 shrink-0 text-destructive-text"
            />
            <span class="truncate" :title="upload.file.name">{{
                upload.file.name
            }}</span>
        </span>
        <span
            class="line-clamp-4 flex-1 leading-snug text-muted-foreground"
            :title="
                upload.message ?? $t(`posts.composer.upload_errors.${upload.reason}`, { size: limitInMb })
            "
            >{{
                upload.message ?? $t(`posts.composer.upload_errors.${upload.reason}`, { size: limitInMb })
            }}</span
        >
        <span class="flex items-center gap-2 font-medium">
            <button
                v-if="isRetryable(upload.reason)"
                type="button"
                class="rounded-sm text-primary-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :data-testid="`${testIdPrefix}-upload-retry-${index}`"
                @click="emit('retry')"
            >
                {{ $t('posts.composer.upload_retry') }}
            </button>
            <button
                type="button"
                class="rounded-sm text-muted-foreground hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :data-testid="`${testIdPrefix}-upload-remove-${index}`"
                @click="emit('remove')"
            >
                {{ $t('posts.composer.upload_remove') }}
            </button>
        </span>
    </div>
</template>
