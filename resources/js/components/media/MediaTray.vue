<script setup lang="ts">
import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { disableNativeDragPreview } from '@atlaskit/pragmatic-drag-and-drop/element/disable-native-drag-preview';
import { reorder } from '@atlaskit/pragmatic-drag-and-drop/reorder';
import { usePage } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconFileTypePdf,
    IconLoader2,
    IconPhotoPlus,
    IconX,
} from '@tabler/icons-vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    useId,
    watch,
    type Directive,
} from 'vue';

import MediaTile from '@/components/media/MediaTile.vue';
import { importErrorKey } from '@/composables/useMediaImport';
import {
    type MediaUploader,
    useMediaUpload,
} from '@/composables/useMediaUpload';
import { cancelDragOnEscape } from '@/lib/dragPlaceholder';
import { editorTabsFor, rulesFor, type EditorTab } from '@/lib/mediaEditor';
import { canvaDesignId, editInCanva } from '@/lib/mediaSources/canva';
import { acceptAttribute, isImage, isVideo, mediaKey } from '@/lib/mediaType';
import { withCoversFor } from '@/lib/videoFrame';
import type { MediaUploadLimits, SharedData } from '@/types';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    items: MediaItem[];
    limits: MediaUploadLimits;
    disabled?: boolean;
    testIdPrefix: string;
    accept?: string;
    uploader?: MediaUploader;
    contentTypes: string[];
    itemErrors?: Record<number, string>;
    /** Hides the add tile; files reach the tray from elsewhere. */
    itemsOnly?: boolean;
}>();

const emit = defineEmits<{
    (event: 'update:items', items: MediaItem[]): void;
    (event: 'busy', busy: boolean): void;
    (event: 'edit', payload: { index: number; tab: EditorTab }): void;
    (
        event: 'import-started',
        payload: { importId: string; label: string; replaces: string | null },
    ): void;
}>();

const uploader =
    props.uploader ??
    useMediaUpload({
        limits: () => props.limits,
        onReady: (item) => emit('update:items', [...props.items, item]),
    });

watch(uploader.busy, (busy) => emit('busy', busy), { immediate: true });

const editorRules = computed(() => rulesFor(props.contentTypes));

/** Whether a network of the post takes a cover image, so the tiles show the one uploaded. */
const takesCoverImage = computed(() =>
    editorRules.value.some((rule) => rule.supportsCustomVideoCover),
);

/** The items as the tiles draw them, without an uploaded cover no network of the post shows. */
const tileItems = computed(() => withCoversFor(props.items, takesCoverImage.value));
const itemTabs = computed<EditorTab[][]>(() =>
    props.items.map((item) => editorTabsFor(item, editorRules.value)),
);

const page = usePage<SharedData>();
const canvaOption = computed(() =>
    page.props.mediaSources?.menu.find((option) => option.source === 'canva'),
);
const canvaEditable = computed<boolean[]>(() =>
    props.items.map(
        (item) => Boolean(canvaOption.value) && canvaDesignId(item) !== null,
    ),
);
const canvaAbort = new AbortController();
onBeforeUnmount(() => canvaAbort.abort());

const editItemInCanva = async (index: number): Promise<void> => {
    const item = props.items[index];
    if (!item || !canvaOption.value || props.disabled) return;

    const result = await editInCanva(item.id, canvaAbort.signal);
    if (result) {
        emit('import-started', {
            importId: result.importId,
            label: canvaOption.value.label,
            replaces: result.replaces ?? item.id,
        });
    }
};

const fileInput = ref<HTMLInputElement | null>(null);
const announcedName = ref('');

watch(
    () => props.items.length,
    (length, previous) => {
        if (length > previous) {
            announcedName.value =
                props.items[length - 1]?.original_filename ?? '';
        }
    },
);

const onFilesSelected = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    if (files.length) uploader.add(files);
};

const onDrop = (event: DragEvent): void => {
    const files = Array.from(event.dataTransfer?.files ?? []);
    if (files.length && !props.disabled) uploader.add(files);
};

const SUGGESTED_LIMIT = 10;
const suggested = defineModel<MediaItem[]>('suggested', {
    default: () => [],
});

const removeItem = (index: number): void => {
    const item = props.items[index];
    emit(
        'update:items',
        props.items.filter((_, itemIndex) => itemIndex !== index),
    );
    if (item) {
        suggested.value = [item, ...suggested.value].slice(0, SUGGESTED_LIMIT);
    }
};

const restoreSuggested = (index: number): void => {
    const item = suggested.value[index];
    if (!item || props.disabled) return;
    emit('update:items', [...props.items, item]);
    suggested.value = suggested.value.filter(
        (_, itemIndex) => itemIndex !== index,
    );
};

const dismissSuggested = (): void => {
    suggested.value = [];
};

const TILE_KEY = 'mediaTrayTile';
const TRAY_KEY = 'mediaTray';
const trayId = useId();
const reorderHintId = `${trayId}-reorder-hint`;
/** While a tile is dragged: where it started and the slot it would land in. */
const dragPreview = ref<{ from: number; to: number } | null>(null);
const moved = ref<{ name: string; position: number; total: number } | null>(
    null,
);

const isTile = (
    data: Record<string | symbol, unknown>,
): data is Record<string | symbol, unknown> & { index: number } =>
    data[TILE_KEY] === trayId && typeof data.index === 'number';

const replacingImports = computed(() =>
    props.items.map((item) =>
        uploader.imports.value.find(
            (pending) =>
                pending.state === 'importing' && pending.replaces === item.id,
        ),
    ),
);

const standaloneImports = computed(() =>
    uploader.imports.value.filter(
        (pending) =>
            !(
                pending.state === 'importing' &&
                pending.replaces !== null &&
                props.items.some((item) => item.id === pending.replaces)
            ),
    ),
);

const tileKeys = computed(() => {
    const seen = new Map<string, number>();

    return props.items.map((item) => {
        const id = mediaKey(item);
        const occurrence = seen.get(id) ?? 0;
        seen.set(id, occurrence + 1);

        return `${id}#${occurrence}`;
    });
});

/** Item indexes in the order shown: the dragged tile sits in its landing slot. */
const displayOrder = computed((): number[] => {
    const indexes = props.items.map((_, index) => index);
    const preview = dragPreview.value;

    return preview
        ? reorder({ list: indexes, startIndex: preview.from, finishIndex: preview.to })
        : indexes;
});

const moveItem = (startIndex: number, finishIndex: number): void => {
    const item = props.items[startIndex];
    if (
        !item ||
        finishIndex === startIndex ||
        finishIndex < 0 ||
        finishIndex >= props.items.length
    ) {
        return;
    }
    emit(
        'update:items',
        reorder({ list: props.items, startIndex, finishIndex }),
    );
    moved.value = {
        name: item.original_filename ?? '',
        position: finishIndex + 1,
        total: props.items.length,
    };
};

const trayElement = ref<HTMLElement | null>(null);

const moveWithKeyboard = (startIndex: number, finishIndex: number): void => {
    moveItem(startIndex, finishIndex);
    nextTick(() =>
        trayElement.value
            ?.querySelector<HTMLElement>(
                `[data-index="${Math.min(Math.max(finishIndex, 0), props.items.length - 1)}"] [data-media-handle]`,
            )
            ?.focus(),
    );
};

/**
 * The slot under the pointer in the wrapped grid of tiles, in reading order,
 * leaving the dragged tile out. Layout offsets (not rects) keep it stable while
 * the tiles move.
 */
const slotAt = (pointer: { clientX: number; clientY: number }, from: number): number => {
    const container = trayElement.value;
    if (!container) return from;

    const rect = container.getBoundingClientRect();
    const x = pointer.clientX - rect.left;
    const y = pointer.clientY - rect.top;
    const rtl = getComputedStyle(container).direction === 'rtl';

    return [...container.querySelectorAll<HTMLElement>(':scope > [data-index]')]
        .filter((tile) => tileIndex(tile) !== from)
        .filter((tile) => {
            const top = tile.offsetTop;
            const bottom = top + tile.offsetHeight;
            const middle = tile.offsetLeft + tile.offsetWidth / 2;

            if (bottom < y) return true;
            if (top > y) return false;

            return rtl ? middle > x : middle < x;
        }).length;
};

let stopEscape: (() => void) | null = null;

const endDrag = (): void => {
    dragPreview.value = null;
    stopEscape?.();
    stopEscape = null;
};

const stopMonitor = monitorForElements({
    canMonitor: ({ source }) => isTile(source.data),
    onDragStart: ({ source }) => {
        if (!isTile(source.data)) return;

        dragPreview.value = { from: source.data.index, to: source.data.index };
        stopEscape = cancelDragOnEscape(endDrag);
    },
    onDrag: ({ location }) => {
        const preview = dragPreview.value;
        if (!preview) return;

        const overTray = location.current.dropTargets.some((target) => target.data[TRAY_KEY] === trayId);
        const to = overTray ? slotAt(location.current.input, preview.from) : preview.from;

        if (to !== preview.to) {
            dragPreview.value = { ...preview, to };
        }
    },
    onDrop: ({ location }) => {
        const preview = dragPreview.value;
        const overTray = location.current.dropTargets.some((target) => target.data[TRAY_KEY] === trayId);

        endDrag();

        if (preview && overTray) {
            moveItem(preview.from, slotAt(location.current.input, preview.from));
        }
    },
});

onBeforeUnmount(() => {
    stopMonitor();
    endDrag();
});

const stopTrayTarget = ref<(() => void) | null>(null);

watch(
    trayElement,
    (element) => {
        stopTrayTarget.value?.();
        stopTrayTarget.value = element
            ? dropTargetForElements({
                  element,
                  canDrop: ({ source }) => isTile(source.data),
                  getData: () => ({ [TRAY_KEY]: trayId }),
              })
            : null;
    },
    { flush: 'post' },
);

onBeforeUnmount(() => stopTrayTarget.value?.());

const tileIndex = (element: HTMLElement): number =>
    Number(element.dataset.index);

const cleanups = new WeakMap<HTMLElement, () => void>();

const vSortableTile: Directive<HTMLElement> = {
    mounted: (element) =>
        cleanups.set(
            element,
            draggable({
                element,
                dragHandle:
                    element.querySelector<HTMLElement>('[data-media-handle]') ??
                    undefined,
                canDrag: () => !props.disabled,
                getInitialData: () => ({
                    [TILE_KEY]: trayId,
                    index: tileIndex(element),
                }),
                onGenerateDragPreview: ({ nativeSetDragImage }) =>
                    disableNativeDragPreview({ nativeSetDragImage }),
                onDragStart: () => element.setAttribute('data-dragging', ''),
                onDrop: () => element.removeAttribute('data-dragging'),
            }),
        ),
    unmounted: (element) => {
        cleanups.get(element)?.();
        cleanups.delete(element);
    },
};
</script>

<template>
    <div>
        <div
            ref="trayElement"
            class="relative flex flex-wrap gap-3"
            :data-testid="`${testIdPrefix}-media-tray`"
            @dragover.prevent
            @drop.prevent.stop="onDrop"
        >
            <div
                v-for="index in displayOrder"
                :key="tileKeys[index]"
                v-sortable-tile
                :data-index="index"
                :data-testid="`${testIdPrefix}-media-item-${index}`"
                class="relative shrink-0 rounded-lg transition-[box-shadow,transform] duration-150 data-dragging:z-10 data-dragging:scale-105 data-dragging:cursor-grabbing data-dragging:shadow-lg data-dragging:ring-2 data-dragging:ring-primary"
            >
                <MediaTile
                    :test-id-prefix="testIdPrefix"
                    :index="index"
                    :item="tileItems[index]!"
                    :tabs="itemTabs[index]"
                    :disabled="disabled"
                    :error="itemErrors?.[index]"
                    :movable="items.length > 1"
                    :reorder-hint-id="reorderHintId"
                    :canva-editable="canvaEditable[index]"
                    @remove="removeItem(index)"
                    @edit-canva="editItemInCanva(index)"
                    @edit="emit('edit', $event)"
                    @move="moveWithKeyboard(index, index + $event)"
                />
                <div
                    v-if="replacingImports[index]"
                    role="status"
                    :data-testid="`${testIdPrefix}-replacing-${index}`"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 rounded-lg bg-background/80 p-2 text-center text-xs text-muted-foreground"
                >
                    <IconLoader2
                        aria-hidden="true"
                        class="size-5 animate-spin"
                    />
                    <span>{{ $t('posts.composer.media_sources.importing') }}</span>
                    <button
                        type="button"
                        class="absolute top-1.5 right-1.5 flex size-6 items-center justify-center rounded-md border border-border bg-background/90 text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        :data-testid="`${testIdPrefix}-replacing-cancel-${index}`"
                        :aria-label="$t('posts.composer.upload_cancel')"
                        @click="uploader.cancel(replacingImports[index]!.key)"
                    >
                        <IconX class="size-3.5" />
                    </button>
                </div>
            </div>
            <MediaTile
                v-for="(entry, index) in uploader.entries.value"
                :key="entry.key"
                :test-id-prefix="testIdPrefix"
                :index="index"
                :upload="entry"
                @cancel="uploader.cancel(entry.key)"
                @retry="uploader.retry(entry.key)"
                @remove="uploader.remove(entry.key)"
            />
            <div
                v-for="(pending, index) in standaloneImports"
                :key="pending.key"
                :data-testid="`${testIdPrefix}-import-${pending.state === 'error' ? 'error' : 'item'}`"
                :data-reason="pending.state === 'error' ? pending.reason : undefined"
                :role="pending.state === 'error' ? 'alert' : 'status'"
                class="relative flex size-30 shrink-0 flex-col items-center justify-center gap-2 overflow-hidden rounded-lg border p-2 text-center text-xs"
                :class="
                    pending.state === 'error'
                        ? 'border-destructive/60 bg-destructive/5'
                        : 'bg-muted text-muted-foreground'
                "
            >
                <IconLoader2
                    v-if="pending.state === 'importing'"
                    aria-hidden="true"
                    class="size-5 animate-spin"
                />
                <IconAlertTriangle
                    v-else
                    aria-hidden="true"
                    class="size-4 text-destructive-text"
                />
                <span class="font-medium text-foreground">{{ pending.label }}</span>
                <span class="line-clamp-3 leading-snug">{{
                    pending.state === 'error'
                        ? $t(importErrorKey(pending.reason))
                        : $t('posts.composer.media_sources.importing')
                }}</span>
                <button
                    v-if="pending.state === 'error'"
                    type="button"
                    class="rounded-sm font-medium text-muted-foreground hover:text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :data-testid="`${testIdPrefix}-import-remove-${index}`"
                    @click="uploader.remove(pending.key)"
                >
                    {{ $t('posts.composer.upload_remove') }}
                </button>
                <button
                    v-else
                    type="button"
                    class="absolute top-1.5 right-1.5 flex size-6 items-center justify-center rounded-md border border-border bg-background/90 text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :data-testid="`${testIdPrefix}-import-cancel-${index}`"
                    :aria-label="$t('posts.composer.upload_cancel')"
                    @click="uploader.cancel(pending.key)"
                >
                    <IconX class="size-3.5" />
                </button>
            </div>
            <button
                v-if="!itemsOnly"
                type="button"
                :data-testid="`${testIdPrefix}-dropzone`"
                :disabled="disabled"
                class="flex size-30 shrink-0 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-input p-2 text-center text-sm leading-tight text-muted-foreground transition-control hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:opacity-50"
                @click="fileInput?.click()"
            >
                <IconPhotoPlus class="size-6" stroke-width="1.5" />
                <span
                    >{{ $t('posts.form.drag_and_drop') }}
                    <span class="text-primary-text">{{
                        $t('posts.composer.dropzone_select')
                    }}</span></span
                >
            </button>
            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                tabindex="-1"
                :accept="accept ?? acceptAttribute(limits.heic)"
                :data-testid="`${testIdPrefix}-file-input`"
                @change="onFilesSelected"
            />
            <span class="sr-only" aria-live="polite">{{
                announcedName
                    ? $t('posts.composer.upload_done', { name: announcedName })
                    : ''
            }}</span>
            <span
                class="sr-only"
                aria-live="polite"
                :data-testid="`${testIdPrefix}-media-moved`"
                >{{
                    moved
                        ? $t('posts.composer.media_moved', {
                              name: moved.name,
                              position: String(moved.position),
                              total: String(moved.total),
                          })
                        : ''
                }}</span
            >
            <span :id="reorderHintId" class="hidden">{{
                $t('posts.composer.media_reorder_hint')
            }}</span>
        </div>
        <section
            v-if="suggested.length"
            class="mt-3 rounded-lg border border-border bg-muted p-3"
            :aria-labelledby="`${trayId}-suggested-title`"
            :data-testid="`${testIdPrefix}-suggested-media`"
        >
            <div class="mb-2 flex items-center justify-between gap-2">
                <h3
                    :id="`${trayId}-suggested-title`"
                    class="text-sm font-medium"
                >
                    {{ $t('posts.composer.suggested_media') }}
                </h3>
                <button
                    type="button"
                    class="flex size-6 items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :aria-label="$t('posts.composer.suggested_media_dismiss')"
                    :data-testid="`${testIdPrefix}-suggested-dismiss`"
                    @click="dismissSuggested"
                >
                    <IconX class="size-4" />
                </button>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="(item, index) in suggested"
                    :key="`${mediaKey(item)}-${index}`"
                    type="button"
                    class="size-16 shrink-0 overflow-hidden rounded-md border border-border bg-background transition-control hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:opacity-50"
                    :aria-label="item.original_filename ?? $t('posts.composer.suggested_media')"
                    :disabled="disabled"
                    :data-testid="`${testIdPrefix}-suggested-${index}`"
                    @click="restoreSuggested(index)"
                >
                    <img
                        v-if="isImage(item)"
                        :src="item.url"
                        alt=""
                        draggable="false"
                        class="size-full object-cover"
                    />
                    <video
                        v-else-if="isVideo(item)"
                        :src="item.url"
                        class="size-full object-cover"
                        muted
                        playsinline
                        preload="metadata"
                    />
                    <IconFileTypePdf
                        v-else
                        aria-hidden="true"
                        class="m-auto size-6 text-muted-foreground"
                        stroke-width="1.5"
                    />
                </button>
            </div>
        </section>
    </div>
</template>
