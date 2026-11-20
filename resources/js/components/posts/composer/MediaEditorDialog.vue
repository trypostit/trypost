<script setup lang="ts">
import { IconCrosshair, IconX } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import { show as showMediaFile } from '@/actions/App/Http/Controllers/App/MediaFileController';
import ImageCropStage from '@/components/media/ImageCropStage.vue';
import MediaEditorAltTextPanel from '@/components/posts/composer/MediaEditorAltTextPanel.vue';
import MediaEditorAppearancePanel from '@/components/posts/composer/MediaEditorAppearancePanel.vue';
import MediaEditorCropPanel from '@/components/posts/composer/MediaEditorCropPanel.vue';
import MediaEditorTagsPanel from '@/components/posts/composer/MediaEditorTagsPanel.vue';
import MediaEditorThumbnailPanel from '@/components/posts/composer/MediaEditorThumbnailPanel.vue';
import MediaEditorVideoTimeline from '@/components/posts/composer/MediaEditorVideoTimeline.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { type ImageSize, useImageCrop } from '@/composables/useImageCrop';
import { defaultCropPresets } from '@/lib/contentTypeMediaRules';
import { filled } from '@/lib/helpers';
import {
    createMediaEdit,
    COVER_CROP_PRESET,
    cropPresetsFor,
    editorTabsFor,
    type EditorTab,
    hasMetaChanges,
    hasCoverCropChanges,
    hasPixelChanges,
    needsFrameCapture,
    newCoverImage,
    type CropPresetValue,
    type MediaEdit,
    presetValuesFor,
    renderImageEdit,
    renderMediaEdit,
    rulesFor,
    USER_TAGS_MAX,
} from '@/lib/mediaEditor';
import { isVideo } from '@/lib/mediaType';
import { captureVideoFrame } from '@/lib/videoFrame';
import type { CoverImage, CoverSource, MediaItem, MediaUserTag } from '@/types/media';

export type MediaEditChange = {
    index: number;
    media: MediaItem;
    file: File | null;
    width: number | null;
    height: number | null;
    altText: string | null;
    userTags: MediaUserTag[];
    coverOffsetMs: number | null;
    coverImage: CoverImage | null;
    coverSource: CoverSource;
};

const props = withDefaults(
    defineProps<{
        open: boolean;
        items: MediaItem[];
        initialIndex?: number;
        initialTab?: EditorTab;
        contentTypes: string[];
        aspectBounds?: { min?: number; max?: number };
        mode?: 'media' | 'photo';
        outputSize?: number;
    }>(),
    {
        initialIndex: 0,
        initialTab: 'edit',
        aspectBounds: () => ({}),
        mode: 'media',
        outputSize: 512,
    },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'apply', changes: MediaEditChange[]): void;
}>();

const editingItems = ref<MediaItem[]>([]);
const edits = ref<MediaEdit[]>([]);
const naturals = ref<Record<number, ImageSize>>({});
const activeIndex = ref(0);
const tab = ref<EditorTab>('edit');

const selectTab = (option: EditorTab): void => {
    tab.value = option;
};

const section = ref<'crop' | 'appearance'>('crop');

const selectSection = (option: typeof section.value): void => {
    section.value = option;
};

const onSectionChange = (value: string | number): void => {
    selectSection(value === 'appearance' ? 'appearance' : 'crop');
};

const pendingPoint = ref<{ x: number; y: number } | null>(null);
const tagging = ref(false);
const movingIndex = ref<number | null>(null);
const applying = ref(false);
const failed = ref(false);
const captureFailed = ref(false);
const imageErrors = ref<Record<number, boolean>>({});

const isPhoto = computed(() => props.mode === 'photo');
const basePreset = computed<CropPresetValue>(() =>
    isPhoto.value ? '1:1' : 'original',
);
const rules = computed(() => rulesFor(props.contentTypes));
const presets = computed(() =>
    isPhoto.value
        ? []
        : cropPresetsFor(
              presetValuesFor(rules.value, defaultCropPresets()),
              props.aspectBounds,
          ),
);
const activeItem = computed(() => editingItems.value[activeIndex.value]);
const activeIsVideo = computed(() =>
    activeItem.value ? isVideo(activeItem.value) : false,
);
/** The AI writes alt text for images only. */
const generatesAltText = computed(() => !activeIsVideo.value);
const stageVideoEl = ref<HTMLVideoElement | null>(null);
const stageVideoDurations = ref<Record<number, number>>({});

const seekStageVideo = (): void => {
    if (stageVideoEl.value && stageVideoEl.value.readyState > 0) {
        stageVideoEl.value.currentTime =
            (edit.value?.coverOffsetMs ?? 0) / 1000;
    }
};
const onStageVideoMetadata = (): void => {
    const measured = stageVideoEl.value?.duration;
    if (measured && Number.isFinite(measured)) {
        stageVideoDurations.value[activeIndex.value] = measured;
    }
    seekStageVideo();
};
const activeVideoDuration = computed<number | null>(
    () =>
        activeItem.value?.meta?.duration ??
        stageVideoDurations.value[activeIndex.value] ??
        null,
);
/** On the Thumbnail tab of a video with an image as its cover: the stage frames that image. */
const cropsCover = computed(() => {
    if (!activeIsVideo.value) {
        return false;
    }

    if (tab.value !== 'thumbnail') {
        return false;
    }

    if (!supportsCustomCover.value) {
        return false;
    }

    if (edit.value?.coverSource !== 'image') {
        return false;
    }

    return filled(edit.value?.coverImage);
});

/** On the Thumbnail tab of a video picking a frame: the strip to drag along. */
const showsTimeline = computed(() => {
    if (!activeIsVideo.value) {
        return false;
    }

    if (tab.value !== 'thumbnail') {
        return false;
    }

    if (!supportsCustomCover.value) {
        return true;
    }

    return edit.value?.coverSource === 'frame';
});

/** The video the frame strip runs along, while the Thumbnail tab picks a frame. */
const timelineVideo = computed(() => {
    if (!filled(edit.value)) {
        return null;
    }

    return activeItem.value ?? null;
});

/** The stage shows the video itself, unless it is framing the video's cover image. */
const showsStageVideo = computed(() => {
    if (!activeIsVideo.value) {
        return false;
    }

    return !cropsCover.value;
});

/** The row of items to switch between, when there is more than one to edit. */
const showsItemPicker = computed(() => {
    if (isPhoto.value) {
        return false;
    }

    return editingItems.value.length > 1;
});

const supportsCustomCover = computed(() =>
    rules.value.some((rule) => rule.supportsCustomVideoCover),
);
const tabs = computed<EditorTab[]>(() =>
    activeItem.value ? editorTabsFor(activeItem.value, rules.value) : [],
);
const tabFor = (preferred: EditorTab): EditorTab =>
    tabs.value.includes(preferred) ? preferred : (tabs.value[0] ?? 'edit');
const edit = computed({
    get: () => edits.value[activeIndex.value],
    set: (value: MediaEdit) => {
        edits.value[activeIndex.value] = value;
    },
});
const natural = computed(() => naturals.value[activeIndex.value] ?? null);

/**
 * What the stage and the crop controls work on: the active item, or the
 * active video's cover image while it is being framed. The cover goes
 * through the same stage, crop panel and render as any image.
 */
const stageEdit = computed({
    get: (): MediaEdit | undefined => {
        if (cropsCover.value) {
            return edit.value?.coverImage?.crop;
        }

        return edit.value;
    },
    set: (value: MediaEdit | undefined) => {
        if (!filled(value)) {
            return;
        }

        if (!cropsCover.value) {
            edit.value = value;

            return;
        }

        if (filled(edit.value?.coverImage)) {
            edit.value.coverImage.crop = value;
        }
    },
});
/** The cover's crop, while the stage frames the cover image. */
const coverCropEdit = computed({
    get: (): MediaEdit | undefined => {
        if (!cropsCover.value) {
            return undefined;
        }

        return stageEdit.value;
    },
    set: (value: MediaEdit | undefined) => {
        stageEdit.value = value;
    },
});
const stageNatural = computed(() => {
    if (cropsCover.value) {
        return edit.value?.coverImage?.natural ?? null;
    }

    return natural.value;
});
const stageSrc = computed(() => {
    if (cropsCover.value) {
        return edit.value?.coverImage?.url;
    }

    return activeItem.value?.url;
});
const stageBasePreset = computed<CropPresetValue>(() => {
    if (cropsCover.value) {
        return COVER_CROP_PRESET;
    }

    return basePreset.value;
});
/** A crosshair while a tag is being placed or moved on the stage. */
const stageBoxClass = computed(() => {
    if (tab.value !== 'tags') {
        return '';
    }

    if (tagging.value) {
        return 'cursor-crosshair';
    }

    if (movingIndex.value !== null) {
        return 'cursor-crosshair';
    }

    return '';
});
const stagePresets = computed(() => {
    if (cropsCover.value) {
        return [];
    }

    return presets.value;
});
/** A fixed ratio only moves, except a profile photo's square and a cover's 9:16, which also resize. */
const resizesAnyRatio = computed(() => {
    if (isPhoto.value) {
        return true;
    }

    return cropsCover.value;
});
const cropMode = computed(() => {
    if (cropsCover.value) {
        return true;
    }

    if (tab.value !== 'edit') {
        return false;
    }

    return section.value === 'crop';
});
const { isCentered, centerSelection, resetGeometry } = useImageCrop(
    stageEdit,
    stageNatural,
);
const centerDisabled = computed(
    () => !stageEdit.value?.selection || isCentered.value,
);
const resetDisabled = computed(
    () =>
        !stageEdit.value ||
        (stageEdit.value.preset === stageBasePreset.value &&
            !stageEdit.value.selection &&
            stageEdit.value.quarterTurns % 4 === 0 &&
            !stageEdit.value.flipX &&
            !stageEdit.value.flipY &&
            stageEdit.value.straighten === 0),
);

/**
 * An item to apply: edited, or a video whose picked frame a network that
 * takes no frame time (YouTube, added after the frame was picked) still has
 * to get as a screenshot.
 */
const isChanged = (index: number): boolean => {
    const item = editingItems.value[index];
    const itemEdit = edits.value[index];

    if (!filled(item)) {
        return false;
    }

    if (!filled(itemEdit)) {
        return false;
    }

    if (hasPixelChanges(itemEdit)) {
        return true;
    }

    if (hasMetaChanges(itemEdit, item)) {
        return true;
    }

    return needsFrameCapture(item, itemEdit, rules.value);
};
const changedIndexes = computed(() =>
    editingItems.value.map((_, index) => index).filter(isChanged),
);

const onImageLoad = (size: ImageSize): void => {
    if (!cropsCover.value) {
        naturals.value[activeIndex.value] = size;

        return;
    }

    if (filled(edit.value?.coverImage)) {
        edit.value.coverImage.natural = size;
    }
};

const onImageError = (): void => {
    imageErrors.value[activeIndex.value] = true;
};

const selectItem = (index: number): void => {
    activeIndex.value = index;
    pendingPoint.value = null;
    tagging.value = false;
    movingIndex.value = null;
    tab.value = tabFor(tab.value);
};

const onStagePress = (point: { x: number; y: number }): void => {
    if (tab.value !== 'tags' || !edit.value) return;
    const round = (value: number): number =>
        Math.round(Math.min(1, Math.max(0, value)) * 10000) / 10000;
    const placed = { x: round(point.x), y: round(point.y) };
    const moving = movingIndex.value;

    if (moving !== null) {
        edit.value.userTags = edit.value.userTags.map((tag, index) =>
            index === moving ? { ...tag, ...placed } : tag,
        );
        movingIndex.value = null;
        return;
    }

    if (!tagging.value || edit.value.userTags.length >= USER_TAGS_MAX) return;
    pendingPoint.value = placed;
};

const resetCrop = (): void => resetGeometry(stageBasePreset.value);

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Where to read an item's pixels for the canvas: a stored media row comes
 * through the app's own origin, since the storage host may send no CORS
 * headers and a cross-origin image would block the export.
 */
const pixelSource = (media: MediaItem): string =>
    UUID.test(media.id) && /^https?:/i.test(media.url)
        ? showMediaFile.url(media.id)
        : media.url;

const loadImage = (src: string): Promise<HTMLImageElement> =>
    new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Image failed to load'));
        image.src = src;
    });

/** The cover to save: the picked frame captured as an image when the network takes one, else the edit's own. */
const coverImageFor = async (
    media: MediaItem,
    itemEdit: MediaEdit,
): Promise<CoverImage | null> => {
    if (needsFrameCapture(media, itemEdit, rules.value)) {
        return captureFrame(media, itemEdit.coverOffsetMs ?? 0);
    }

    const cover = itemEdit.coverImage;

    if (!filled(cover)) {
        return null;
    }

    if (!filled(cover.file) && !hasCoverCropChanges(cover.crop)) {
        return cover;
    }

    return renderCover(cover);
};

const captureFrame = async (
    media: MediaItem,
    milliseconds: number,
): Promise<CoverImage> => {
    try {
        const file = await captureVideoFrame(media.url, milliseconds);

        return newCoverImage(URL.createObjectURL(file), null, file);
    } catch (error) {
        captureFailed.value = true;

        throw error;
    }
};

/** The cover image as framed on the stage, rendered like any edited image. */
const renderCover = async (cover: CoverImage): Promise<CoverImage> => {
    const source = coverPixelItem(cover);
    const image = await loadImage(pixelSource(source));
    const rendered = await renderMediaEdit(image, source, cover.crop);

    return newCoverImage(URL.createObjectURL(rendered.file), null, rendered.file);
};

/** The cover as a media item to read its pixels from: the picked file, or the stored image. */
const coverPixelItem = (cover: CoverImage): MediaItem => {
    if (filled(cover.file)) {
        return {
            id: cover.url,
            url: cover.url,
            original_filename: cover.file.name,
            mime_type: cover.file.type,
        };
    }

    if (filled(cover.item)) {
        return cover.item;
    }

    return { id: cover.url, url: cover.url };
};

const failureKey = computed(() => {
    if (captureFailed.value) {
        return 'posts.composer.media_editor.thumbnail_capture_failed';
    }

    return 'posts.composer.media_editor.apply_failed';
});

const apply = async (): Promise<void> => {
    applying.value = true;
    failed.value = false;
    captureFailed.value = false;
    try {
        const changes = await Promise.all(
            changedIndexes.value.map(
                async (index): Promise<MediaEditChange> => {
                    const media = editingItems.value[index];
                    const itemEdit = edits.value[index];
                    const image = hasPixelChanges(itemEdit)
                        ? await loadImage(pixelSource(media))
                        : null;
                    const rendered = !image
                        ? null
                        : isPhoto.value
                          ? await renderImageEdit(image, itemEdit, {
                                fileName:
                                    media.original_filename ?? 'image.png',
                                mimeType: media.mime_type ?? 'image/png',
                                outputSide: props.outputSize,
                            })
                          : await renderMediaEdit(image, media, itemEdit);

                    return {
                        index,
                        media,
                        file: rendered?.file ?? null,
                        width: rendered?.width ?? null,
                        height: rendered?.height ?? null,
                        altText: itemEdit.altText.trim() || null,
                        userTags: itemEdit.userTags,
                        coverOffsetMs: itemEdit.coverOffsetMs,
                        coverImage: await coverImageFor(media, itemEdit),
                        coverSource: itemEdit.coverSource,
                    };
                },
            ),
        );
        emit('apply', changes);
        emit('update:open', false);
    } catch {
        failed.value = true;
    } finally {
        applying.value = false;
    }
};

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;

        editingItems.value = [...props.items];
        edits.value = editingItems.value.map((item) => ({
            ...createMediaEdit(item),
            preset: basePreset.value,
        }));
        naturals.value = {};
        stageVideoDurations.value = {};
        imageErrors.value = {};
        activeIndex.value = Math.min(
            props.initialIndex,
            Math.max(0, editingItems.value.length - 1),
        );
        tab.value = tabFor(props.initialTab);
        section.value = 'crop';
        pendingPoint.value = null;
        tagging.value = false;
        movingIndex.value = null;
        failed.value = false;
        captureFailed.value = false;
    },
);

watch(() => edit.value?.coverOffsetMs, seekStageVideo);

watch([tab, section], () => {
    pendingPoint.value = null;
    tagging.value = false;
    movingIndex.value = null;
});

watch(tagging, (isTagging) => {
    if (!isTagging) {
        pendingPoint.value = null;
    }
});
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="top-0 left-0 flex h-dvh max-h-dvh w-screen max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-none border-0 p-0 shadow-none outline-none sm:max-w-none"
            data-testid="media-editor"
            :show-close-button="false"
        >
            <header class="flex items-center justify-between px-4 pt-3 pb-2">
                <DialogTitle class="text-lg font-medium">{{
                    $t(
                        isPhoto
                            ? 'common.photo_upload.crop_title'
                            : 'posts.composer.media_editor.title',
                    )
                }}</DialogTitle>
                <DialogDescription class="sr-only">{{
                    $t('posts.composer.media_editor.description')
                }}</DialogDescription>
                <DialogClose
                    data-testid="media-editor-close"
                    :aria-label="$t('common.close')"
                    class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                >
                    <IconX class="size-4" />
                </DialogClose>
            </header>

            <div
                class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 lg:flex-row lg:overflow-hidden"
            >
                <div class="flex min-w-0 shrink-0 flex-col gap-3 lg:min-h-0 lg:flex-1">
                    <div
                        class="relative flex min-h-[45vh] flex-1 items-center justify-center overflow-hidden rounded-xl bg-muted select-none dark:bg-accent lg:min-h-0"
                    >
                        <template v-if="showsStageVideo">
                            <video
                                v-if="activeItem"
                                ref="stageVideoEl"
                                :key="activeItem.url"
                                :src="activeItem.url"
                                muted
                                playsinline
                                preload="metadata"
                                data-testid="media-editor-stage-video"
                                class="absolute inset-6 size-[calc(100%-3rem)] object-contain"
                                @loadedmetadata="onStageVideoMetadata"
                            />
                        </template>
                        <p
                            v-else-if="imageErrors[activeIndex]"
                            class="p-4 text-center text-sm text-muted-foreground"
                        >
                            {{ $t('common.photo_upload.crop_error') }}
                        </p>
                        <template v-else-if="stageSrc">
                            <ImageCropStage
                                v-if="stageEdit"
                                v-model:edit="stageEdit"
                                :src="stageSrc"
                                :natural="stageNatural"
                                :crop-mode="cropMode"
                                :resize-any-ratio="resizesAnyRatio"
                                test-id-prefix="media-editor"
                                :box-class="stageBoxClass"
                                @load="onImageLoad"
                                @error="onImageError"
                                @press="onStagePress"
                            >
                                <template v-if="tab === 'tags'">
                                    <span
                                        v-for="(tag, tagIndex) in edit.userTags"
                                        :key="`${tag.username}-${tagIndex}`"
                                        class="pointer-events-none absolute flex size-6 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full text-xs font-medium tabular-nums shadow ring-2 ring-white transition-transform"
                                        :class="
                                            movingIndex === tagIndex
                                                ? 'scale-125 bg-primary-strong text-primary-strong-foreground ring-primary-strong/30'
                                                : 'bg-foreground text-background'
                                        "
                                        :data-testid="`media-editor-tag-marker-${tagIndex}`"
                                        :style="{
                                            left: `${tag.x * 100}%`,
                                            top: `${tag.y * 100}%`,
                                        }"
                                        >{{ tagIndex + 1 }}</span
                                    >
                                    <span
                                        v-if="movingIndex !== null"
                                        class="pointer-events-none absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-foreground/85 px-3 py-1 text-xs whitespace-nowrap text-background shadow"
                                        data-testid="media-editor-tag-move-pill"
                                    >
                                        <IconCrosshair class="size-3.5" />
                                        {{
                                            $t(
                                                'posts.composer.media_editor.tags_move_pill',
                                            )
                                        }}
                                    </span>
                                    <span
                                        v-if="pendingPoint"
                                        data-testid="media-editor-tag-point"
                                        class="pointer-events-none absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-foreground/40 shadow"
                                        :style="{
                                            left: `${pendingPoint.x * 100}%`,
                                            top: `${pendingPoint.y * 100}%`,
                                        }"
                                    />
                                </template>
                            </ImageCropStage>
                        </template>
                    </div>

                    <template v-if="showsTimeline">
                        <MediaEditorVideoTimeline
                            v-if="timelineVideo"
                            :key="timelineVideo.url"
                            v-model:edit="edit"
                            :src="timelineVideo.url"
                            :duration="activeVideoDuration"
                        />
                    </template>
                    <div
                        v-if="showsItemPicker"
                        class="flex justify-center gap-3 overflow-x-auto px-1 pt-3 pb-1.5 [scrollbar-width:thin]"
                        data-testid="media-editor-thumbnails"
                    >
                        <button
                            v-for="(item, index) in editingItems"
                            :key="`${item.id}-${index}`"
                            type="button"
                            :data-testid="`media-editor-thumb-${index}`"
                            :aria-label="
                                $t(
                                    isVideo(item)
                                        ? 'posts.composer.media_editor.video_thumbnail'
                                        : 'posts.composer.media_editor.thumbnail',
                                    { number: String(index + 1) },
                                )
                            "
                            :aria-current="index === activeIndex"
                            class="relative size-16 shrink-0 overflow-hidden rounded-lg"
                            :class="
                                index === activeIndex
                                    ? 'ring-2 ring-primary-strong'
                                    : 'opacity-70 hover:opacity-100'
                            "
                            @click="selectItem(index)"
                        >
                            <video
                                v-if="isVideo(item)"
                                :src="item.url"
                                muted
                                playsinline
                                preload="metadata"
                                class="size-full object-cover"
                            />
                            <img
                                v-else
                                :src="item.url"
                                alt=""
                                class="size-full object-cover"
                            />
                            <span
                                v-if="isChanged(index)"
                                class="absolute top-1 right-1 size-2 rounded-full bg-primary-strong ring-2 ring-background"
                            />
                        </button>
                    </div>
                </div>

                <aside
                    class="flex w-full shrink-0 flex-col gap-4 lg:w-80 lg:overflow-y-auto"
                >
                    <div
                        v-if="tabs.length > 1 || tabs[0] === 'thumbnail'"
                        role="group"
                        data-testid="media-editor-segments"
                        class="grid rounded-lg border border-border p-1"
                        :class="
                            ['grid-cols-1', 'grid-cols-2', 'grid-cols-3'][
                                tabs.length - 1
                            ]
                        "
                    >
                        <button
                            v-for="option in tabs"
                            :key="option"
                            type="button"
                            :aria-pressed="tab === option"
                            :data-testid="`media-editor-${option}-tab`"
                            class="rounded-md px-2 py-1.5 text-sm whitespace-nowrap transition-colors"
                            :class="
                                tab === option
                                    ? 'bg-primary-selected font-medium text-primary-text'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="selectTab(option)"
                        >
                            {{
                                $t(`posts.composer.media_editor.${option}_tab`)
                            }}
                        </button>
                    </div>

                    <template v-if="tab === 'edit' && edit">
                        <Tabs
                            :model-value="section"
                            @update:model-value="onSectionChange"
                        >
                            <TabsList variant="line" class="grid grid-cols-2 gap-0">
                                <TabsTrigger
                                    v-for="option in ['crop', 'appearance'] as const"
                                    :key="option"
                                    :value="option"
                                    :data-testid="`media-editor-${option}-section`"
                                >
                                    {{
                                        $t(
                                            `posts.composer.media_editor.${option}_tab`,
                                        )
                                    }}
                                </TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <MediaEditorCropPanel
                            v-if="section === 'crop'"
                            v-model:edit="edit"
                            :presets="stagePresets"
                            :center-disabled="centerDisabled"
                            :reset-disabled="resetDisabled"
                            @center="centerSelection"
                            @reset="resetCrop"
                        />
                        <MediaEditorAppearancePanel
                            v-else
                            v-model:edit="edit"
                            :src="activeItem.url"
                        />
                    </template>
                    <template v-else-if="tab === 'thumbnail' && edit">
                        <MediaEditorThumbnailPanel
                            :key="activeIndex"
                            v-model:edit="edit"
                            :supports-custom-cover="supportsCustomCover"
                        />
                        <MediaEditorCropPanel
                            v-if="coverCropEdit"
                            v-model:edit="coverCropEdit"
                            :presets="stagePresets"
                            :center-disabled="centerDisabled"
                            :reset-disabled="resetDisabled"
                            @center="centerSelection"
                            @reset="resetCrop"
                        />
                    </template>
                    <MediaEditorAltTextPanel
                        v-else-if="tab === 'alt' && edit"
                        v-model:edit="edit"
                        :media-id="activeItem.id"
                        :generates="generatesAltText"
                    />
                    <MediaEditorTagsPanel
                        v-else-if="tab === 'tags' && edit"
                        v-model:edit="edit"
                        v-model:pending-point="pendingPoint"
                        v-model:tagging="tagging"
                        v-model:moving-index="movingIndex"
                    />
                </aside>
            </div>

            <DialogFooter class="m-2 shrink-0 flex-wrap">
                <p
                    v-if="failed"
                    class="mr-auto text-sm text-destructive"
                    data-testid="media-editor-error"
                >
                    {{ $t(failureKey) }}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    data-testid="media-editor-cancel"
                    @click="emit('update:open', false)"
                >
                    {{ $t('posts.composer.media_editor.cancel') }}
                </Button>
                <Button
                    type="button"
                    data-testid="media-editor-apply"
                    :disabled="applying || changedIndexes.length === 0"
                    @click="apply"
                >
                    {{
                        isPhoto
                            ? $t('common.photo_upload.crop_save')
                            : changedIndexes.length > 0
                            ? $tChoice(
                                  'posts.composer.media_editor.apply_count',
                                  changedIndexes.length,
                                  { count: String(changedIndexes.length) },
                              )
                            : $t('posts.composer.media_editor.apply')
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
