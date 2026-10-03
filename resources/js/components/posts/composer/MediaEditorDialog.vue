<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import ImageCropStage from '@/components/media/ImageCropStage.vue';
import MediaEditorAltTextPanel from '@/components/posts/composer/MediaEditorAltTextPanel.vue';
import MediaEditorAppearancePanel from '@/components/posts/composer/MediaEditorAppearancePanel.vue';
import MediaEditorCropPanel from '@/components/posts/composer/MediaEditorCropPanel.vue';
import MediaEditorTagsPanel from '@/components/posts/composer/MediaEditorTagsPanel.vue';
import MediaEditorThumbnailPanel from '@/components/posts/composer/MediaEditorThumbnailPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { type ImageSize, useImageCrop } from '@/composables/useImageCrop';
import { defaultCropPresets } from '@/lib/contentTypeMediaRules';
import {
    createMediaEdit,
    cropPresetsFor,
    editorTabsFor,
    type EditorTab,
    hasMetaChanges,
    hasPixelChanges,
    type CropPresetValue,
    type MediaEdit,
    presetValuesFor,
    renderImageEdit,
    renderMediaEdit,
    rulesFor,
    USER_TAGS_MAX,
} from '@/lib/mediaEditor';
import { isVideo } from '@/lib/mediaType';
import type { MediaItem, MediaUserTag } from '@/types/media';

export type MediaEditChange = {
    index: number;
    media: MediaItem;
    file: File | null;
    width: number | null;
    height: number | null;
    altText: string | null;
    userTags: MediaUserTag[];
    coverOffsetMs: number | null;
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

const pendingPoint = ref<{ x: number; y: number } | null>(null);
const tagging = ref(false);
const applying = ref(false);
const failed = ref(false);
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
const cropMode = computed(
    () => tab.value === 'edit' && section.value === 'crop',
);
const { isCentered, centerSelection, resetGeometry } = useImageCrop(
    edit,
    natural,
);
const centerDisabled = computed(
    () => !edit.value?.selection || isCentered.value,
);
const resetDisabled = computed(
    () =>
        !edit.value ||
        (edit.value.preset === basePreset.value &&
            !edit.value.selection &&
            edit.value.quarterTurns % 4 === 0 &&
            !edit.value.flipX &&
            !edit.value.flipY &&
            edit.value.straighten === 0),
);

const isChanged = (index: number): boolean => {
    const item = editingItems.value[index];
    const itemEdit = edits.value[index];

    return Boolean(
        item &&
            itemEdit &&
            (hasPixelChanges(itemEdit) || hasMetaChanges(itemEdit, item)),
    );
};
const changedIndexes = computed(() =>
    editingItems.value.map((_, index) => index).filter(isChanged),
);

const onImageLoad = (size: ImageSize): void => {
    naturals.value[activeIndex.value] = size;
};

const onImageError = (): void => {
    imageErrors.value[activeIndex.value] = true;
};

const selectItem = (index: number): void => {
    activeIndex.value = index;
    pendingPoint.value = null;
    tagging.value = false;
    tab.value = tabFor(tab.value);
};

const onStagePress = (point: { x: number; y: number }): void => {
    if (
        tab.value !== 'tags' ||
        !tagging.value ||
        edit.value.userTags.length >= USER_TAGS_MAX
    )
        return;
    const round = (value: number): number =>
        Math.round(Math.min(1, Math.max(0, value)) * 10000) / 10000;
    pendingPoint.value = { x: round(point.x), y: round(point.y) };
};

const resetCrop = (): void => resetGeometry(basePreset.value);

const loadImage = (src: string): Promise<HTMLImageElement> =>
    new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Image failed to load'));
        image.src = src;
    });

const apply = async (): Promise<void> => {
    applying.value = true;
    failed.value = false;
    try {
        const changes = await Promise.all(
            changedIndexes.value.map(
                async (index): Promise<MediaEditChange> => {
                    const media = editingItems.value[index];
                    const itemEdit = edits.value[index];
                    const image = hasPixelChanges(itemEdit)
                        ? await loadImage(media.url)
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
        failed.value = false;
    },
);

watch(() => edit.value?.coverOffsetMs, seekStageVideo);

watch([tab, section], () => {
    pendingPoint.value = null;
    tagging.value = false;
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
            class="flex h-dvh max-h-dvh w-screen max-w-none flex-col gap-0 overflow-hidden rounded-none border-0 p-0 sm:max-w-none"
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
                <div class="flex min-h-0 flex-1 flex-col gap-3">
                    <div
                        class="relative flex min-h-[45vh] flex-1 items-center justify-center overflow-hidden rounded-xl bg-muted select-none dark:bg-accent lg:min-h-0"
                    >
                        <video
                            v-if="activeItem && activeIsVideo"
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
                        <p
                            v-else-if="imageErrors[activeIndex]"
                            class="p-4 text-center text-sm text-muted-foreground"
                        >
                            {{ $t('common.photo_upload.crop_error') }}
                        </p>
                        <ImageCropStage
                            v-else-if="activeItem && edit"
                            v-model:edit="edit"
                            :src="activeItem.url"
                            :natural="natural"
                            :crop-mode="cropMode"
                            test-id-prefix="media-editor"
                            :box-class="
                                tab === 'tags' && tagging
                                    ? 'cursor-crosshair'
                                    : ''
                            "
                            @load="onImageLoad"
                            @error="onImageError"
                            @press="onStagePress"
                        >
                            <template v-if="tab === 'tags'">
                                <span
                                    v-for="(tag, tagIndex) in edit.userTags"
                                    :key="`${tag.username}-${tagIndex}`"
                                    class="pointer-events-none absolute flex size-6 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-foreground text-xs font-medium text-background shadow"
                                    :style="{
                                        left: `${tag.x * 100}%`,
                                        top: `${tag.y * 100}%`,
                                    }"
                                    >{{ tagIndex + 1 }}</span
                                >
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
                    </div>

                    <div
                        v-if="!isPhoto"
                        class="flex justify-center gap-2 overflow-x-auto pb-1"
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
                            class="relative size-13 shrink-0 overflow-hidden rounded-lg"
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
                    class="flex w-full shrink-0 flex-col gap-4 lg:w-70 lg:overflow-y-auto"
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
                        <div
                            role="group"
                            class="grid grid-cols-2 border-b border-border"
                        >
                            <button
                                v-for="option in ['crop', 'appearance'] as const"
                                :key="option"
                                type="button"
                                :aria-pressed="section === option"
                                :data-testid="`media-editor-${option}-section`"
                                class="-mb-px border-b-2 py-2 text-sm font-medium transition-colors"
                                :class="
                                    section === option
                                        ? 'border-primary-strong text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                "
                                @click="selectSection(option)"
                            >
                                {{
                                    $t(
                                        `posts.composer.media_editor.${option}_tab`,
                                    )
                                }}
                            </button>
                        </div>
                        <MediaEditorCropPanel
                            v-if="section === 'crop'"
                            v-model:edit="edit"
                            :presets="presets"
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
                    <MediaEditorThumbnailPanel
                        v-else-if="tab === 'thumbnail' && edit"
                        v-model:edit="edit"
                        :duration="activeVideoDuration"
                    />
                    <MediaEditorAltTextPanel
                        v-else-if="tab === 'alt' && edit"
                        v-model:edit="edit"
                        :media-id="activeItem.id"
                    />
                    <MediaEditorTagsPanel
                        v-else-if="tab === 'tags' && edit"
                        v-model:edit="edit"
                        v-model:pending-point="pendingPoint"
                        v-model:tagging="tagging"
                    />
                </aside>
            </div>

            <footer
                class="flex flex-wrap items-center justify-end gap-2 px-4 py-3"
            >
                <p
                    v-if="failed"
                    class="mr-auto text-sm text-destructive"
                    data-testid="media-editor-error"
                >
                    {{ $t('posts.composer.media_editor.apply_failed') }}
                </p>
                <Button
                    type="button"
                    variant="outline"
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
            </footer>
        </DialogContent>
    </Dialog>
</template>
