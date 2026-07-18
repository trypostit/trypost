<script setup lang="ts">
import {
    IconAlertTriangle,
    IconAlt,
    IconFileTypePdf,
    IconGripVertical,
    IconHash,
    IconLibraryPhoto,
    IconMoodSmile,
    IconRefresh,
    IconSparkles,
    IconTrash,
    IconVideo,
    IconWriting,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref } from 'vue';

import ImagePreviewDialog from '@/components/ImagePreviewDialog.vue';
import AltTextDialog from '@/components/posts/editor/AltTextDialog.vue';
import EmojiPicker from '@/components/posts/EmojiPicker.vue';
import MediaPickerDialog from '@/components/posts/MediaPickerDialog.vue';
import SignaturesModal from '@/components/posts/SignaturesModal.vue';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import date from '@/date';
import { classify, isDocument, isImage, isVideo, MediaType } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

interface Signature {
    id: string;
    name: string;
    content: string;
}

interface PlatformLimit {
    platform: string;
    maxLength: number;
}

interface MediaIssue {
    platform: string;
    reason: string;
}

const props = withDefaults(
    defineProps<{
        signatures: Signature[];
        platformLimits: PlatformLimit[];
        mediaIssues: Record<string, MediaIssue[]>;
        allowAiRegenerate?: boolean;
        readOnly?: boolean;
    }>(),
    {
        allowAiRegenerate: true,
        readOnly: false,
    },
);

const content = defineModel<string>('content', { required: true });
const media = defineModel<MediaItem[]>('media', { required: true });

const emit = defineEmits<{
    (e: 'open-ai-generate'): void;
    (e: 'open-ai-review'): void;
    (e: 'open-ai-regenerate-image', mediaId: string): void;
}>();

const emojiOpen = ref(false);
const mediaPickerDialog = ref<InstanceType<typeof MediaPickerDialog> | null>(null);
const signaturesModal = ref<InstanceType<typeof SignaturesModal> | null>(null);

const dragMediaIndex = ref<number | null>(null);
const dragOverIndex = ref<number | null>(null);
const mediaThumbRefs = ref<HTMLElement[]>([]);
const lightbox = ref<InstanceType<typeof ImagePreviewDialog> | null>(null);

const openPreview = (item: MediaItem) => {
    const idx = media.value.findIndex((m) => m.id === item.id);
    if (idx < 0) return;
    lightbox.value?.openCollection(
        media.value.map((m) => ({
            url: m.url,
            type: classify(m) ?? MediaType.Image,
            altText: isImage(m) ? m.meta?.alt_text : undefined,
        })),
        idx,
    );
};

const limitsWithUsage = computed(() =>
    props.platformLimits.map((p) => {
        const used = content.value.length;
        const ratio = p.maxLength > 0 ? used / p.maxLength : 0;
        const state = ratio > 1 ? 'over' : ratio >= 0.9 ? 'warn' : 'ok';
        return { ...p, used, state };
    }),
);

const limitClass = (state: string): string => {
    if (state === 'over') return 'border-foreground bg-rose-100 text-rose-700';
    if (state === 'warn') return 'border-foreground bg-amber-100 text-amber-800';
    return 'border-foreground bg-card text-foreground';
};

const smallestLimit = computed(() => {
    if (props.platformLimits.length === 0) return null;
    return Math.min(...props.platformLimits.map((p) => p.maxLength));
});

const overflowParts = computed(() => {
    const limit = smallestLimit.value;
    if (limit === null || content.value.length <= limit) {
        return { fits: content.value, overflow: '' };
    }
    return {
        fits: content.value.slice(0, limit),
        overflow: content.value.slice(limit),
    };
});

const removeMedia = (mediaId: string) => {
    media.value = media.value.filter((m) => m.id !== mediaId);
};

const addMediaFromGallery = (picked: MediaItem[]) => {
    const existingIds = new Set(media.value.map((m) => m.id));
    const additions = picked.filter((m) => !existingIds.has(m.id));
    if (additions.length === 0) return;
    media.value = [...media.value, ...additions];
};

const appendSignature = (signature: Signature) => {
    const separator = content.value.trim() ? '\n\n' : '';
    content.value += separator + signature.content;
};

const appendEmoji = (emoji: string) => {
    content.value += emoji;
    emojiOpen.value = false;
};

const moveMediaItem = (from: number, to: number) => {
    if (from === to || to < 0 || to >= media.value.length) return;
    const next = [...media.value];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);
    media.value = next;
};

const onMediaDragStart = (event: DragEvent, index: number) => {
    dragMediaIndex.value = index;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(index));
    }
};

const onMediaDragOver = (event: DragEvent, index: number) => {
    if (dragMediaIndex.value === null) return;
    event.preventDefault();
    event.stopPropagation();
    dragOverIndex.value = index;
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
};

const onMediaDrop = (event: DragEvent, index: number) => {
    if (dragMediaIndex.value === null) return;
    event.preventDefault();
    event.stopPropagation();
    const from = dragMediaIndex.value;
    dragMediaIndex.value = null;
    dragOverIndex.value = null;
    moveMediaItem(from, index);
};

const onMediaDragEnd = () => {
    dragMediaIndex.value = null;
    dragOverIndex.value = null;
};

const GRID_COLS = 4;

const onMediaKeydown = async (event: KeyboardEvent, index: number) => {
    if (props.readOnly) return;
    if (media.value.length < 2) return;
    if (! event.altKey) return;

    const deltas: Record<string, number> = {
        ArrowLeft: -1,
        ArrowRight: 1,
        ArrowUp: -GRID_COLS,
        ArrowDown: GRID_COLS,
    };
    const delta = deltas[event.key];
    if (delta === undefined) return;

    event.preventDefault();
    const target = index + delta;
    if (target < 0 || target >= media.value.length) return;
    moveMediaItem(index, target);

    await nextTick();
    mediaThumbRefs.value[target]?.focus();
};

const issueLabel = (reason: string): string => trans(`posts.form.warnings.${reason}`);
const canRegenerateWithAi = (item: MediaItem): boolean => props.allowAiRegenerate && item.source === 'ai';

const altDialogOpen = ref(false);
const altDialogIndex = ref<number | null>(null);
const altDialogItem = computed<MediaItem | null>(() =>
    altDialogIndex.value !== null ? (media.value[altDialogIndex.value] ?? null) : null,
);

const openAltText = (index: number) => {
    altDialogIndex.value = index;
    altDialogOpen.value = true;
};

const onAltTextSave = (alt: string): void => {
    if (altDialogIndex.value === null) return;

    const next = [...media.value];
    const trimmed = alt.trim();
    next[altDialogIndex.value] = {
        ...next[altDialogIndex.value],
        meta: { ...(next[altDialogIndex.value].meta ?? {}), alt_text: trimmed || undefined },
    };
    media.value = next;
};
</script>

<template>
    <div class="mx-auto max-w-2xl px-6 py-10">
        <div class="relative">
            <!-- Media grid (top) — always shown so "Add" tile is discoverable -->
            <div v-if="!readOnly || media.length" class="mb-6">
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <div
                        v-for="(item, index) in media"
                        :key="item.id"
                        :ref="(el) => { if (el) mediaThumbRefs[index] = el as HTMLElement; }"
                        data-testid="media-thumbnail"
                        class="group relative aspect-square cursor-zoom-in overflow-hidden rounded-xl border-2 border-foreground bg-muted shadow-2xs transition-all focus:outline-none focus:ring-2 focus:ring-foreground focus:ring-offset-2"
                        :class="[
                            dragMediaIndex === index ? 'opacity-40' : '',
                            dragOverIndex === index && dragMediaIndex !== index ? 'ring-2 ring-foreground ring-offset-2' : '',
                            mediaIssues[item.id] ? '!border-rose-500' : '',
                        ]"
                        tabindex="0"
                        :draggable="!readOnly && media.length > 1"
                        @click="openPreview(item)"
                        @dragstart="onMediaDragStart($event, index)"
                        @dragover="onMediaDragOver($event, index)"
                        @drop="onMediaDrop($event, index)"
                        @dragend="onMediaDragEnd"
                        @keydown="onMediaKeydown($event, index)"
                    >
                        <video
                            v-if="isVideo(item)"
                            :src="item.url"
                            class="h-full w-full object-cover"
                            muted
                        />
                        <div
                            v-else-if="isDocument(item)"
                            class="flex h-full w-full flex-col items-center justify-center gap-1 bg-rose-50 p-2 text-center"
                        >
                            <IconFileTypePdf class="size-7 text-rose-600" />
                            <span class="line-clamp-2 break-all text-[10px] font-medium text-foreground/70">{{ item.original_filename || 'PDF' }}</span>
                        </div>
                        <img
                            v-else
                            :src="item.url"
                            :alt="item.original_filename"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />

                        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-12 bg-gradient-to-t from-black/60 to-transparent opacity-0 transition-opacity group-hover:opacity-100" />

                        <div class="pointer-events-none absolute inset-x-1.5 bottom-1.5 flex flex-col items-start gap-1 text-[10px] font-medium text-white">
                            <span
                                v-if="isVideo(item) && item.meta?.duration"
                                class="inline-flex items-center gap-0.5 rounded-md bg-black/65 px-1.5 py-0.5 backdrop-blur-sm"
                            >
                                <IconVideo class="size-2.5" />
                                {{ date.formatClock(item.meta.duration) }}
                            </span>
                            <span
                                v-if="isImage(item) && item.meta?.alt_text"
                                class="inline-block max-w-full truncate rounded-md bg-black/65 px-1.5 py-0.5 backdrop-blur-sm"
                                data-testid="alt-text-badge"
                            >
                                {{ item.meta.alt_text }}
                            </span>
                        </div>

                        <TooltipProvider v-if="mediaIssues[item.id]" :delay-duration="100">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <span class="absolute bottom-1.5 right-1.5 inline-flex h-5 items-center gap-0.5 rounded-full border-2 border-foreground bg-rose-100 px-1.5 text-[10px] font-bold text-rose-700 shadow-2xs">
                                        <IconAlertTriangle class="size-2.5" />
                                        {{ mediaIssues[item.id].length }}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <ul class="space-y-1 text-xs">
                                        <li v-for="iss in mediaIssues[item.id]" :key="iss.platform" class="flex items-center gap-1.5">
                                            <img :src="getPlatformLogo(iss.platform)" :alt="iss.platform" class="size-3 object-contain" />
                                            <span class="font-medium">{{ getPlatformLabel(iss.platform) }}:</span>
                                            <span class="opacity-80">{{ issueLabel(iss.reason) }}</span>
                                        </li>
                                    </ul>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>

                        <span
                            v-if="media.length > 1 && !readOnly"
                            class="absolute left-1.5 top-1.5 inline-flex size-6 cursor-grab items-center justify-center rounded-md border-2 border-foreground bg-card text-foreground opacity-100 shadow-2xs lg:opacity-0 transition-opacity lg:group-hover:opacity-100 lg:group-focus:opacity-100"
                        >
                            <IconGripVertical class="size-3.5" />
                        </span>

                        <button
                            v-if="canRegenerateWithAi(item)"
                            type="button"
                            class="absolute bottom-1.5 left-1.5 inline-flex h-6 cursor-pointer items-center gap-1 rounded-md border-2 border-foreground bg-card px-1.5 text-[10px] font-semibold text-foreground opacity-100 shadow-2xs lg:opacity-0 transition-all hover:bg-violet-100 lg:group-hover:opacity-100 lg:group-focus:opacity-100"
                            @click.stop="emit('open-ai-regenerate-image', item.id)"
                        >
                            <IconRefresh class="size-3" />
                            {{ $t('posts.ai.image_regenerate.button') }}
                        </button>

                        <button
                            v-if="!readOnly && isImage(item)"
                            type="button"
                            :title="$t('posts.edit.alt_text.edit')"
                            :aria-label="$t('posts.edit.alt_text.edit')"
                            class="absolute right-9 top-1.5 inline-flex size-6 cursor-pointer items-center justify-center rounded-md border-2 border-foreground bg-card text-foreground opacity-100 shadow-2xs lg:opacity-0 transition-all hover:bg-violet-100 lg:group-hover:opacity-100 lg:group-focus:opacity-100"
                            data-testid="alt-text-button"
                            @click.stop="openAltText(index)"
                        >
                            <IconAlt class="size-3.5" />
                        </button>

                        <button
                            v-if="!readOnly"
                            type="button"
                            class="absolute right-1.5 top-1.5 inline-flex size-6 cursor-pointer items-center justify-center rounded-md border-2 border-foreground bg-card text-foreground opacity-100 shadow-2xs lg:opacity-0 transition-all hover:bg-rose-100 hover:text-rose-700 lg:group-hover:opacity-100 lg:group-focus:opacity-100"
                            data-testid="media-remove"
                            @click.stop="removeMedia(item.id)"
                        >
                            <IconTrash class="size-3.5" />
                        </button>
                    </div>

                    <button
                        v-if="!readOnly"
                        type="button"
                        class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-foreground/25 text-foreground/60 transition-colors hover:border-foreground hover:bg-foreground/5 hover:text-foreground"
                        @click="mediaPickerDialog?.open()"
                    >
                        <IconLibraryPhoto class="size-5" />
                        <span class="text-[10px] font-bold uppercase tracking-widest">{{ $t('posts.edit.add') }}</span>
                    </button>
                </div>
            </div>

            <!-- Toolbar: between photos and textarea -->
            <div v-if="!readOnly" class="mb-4 flex items-center gap-2">
                <Popover v-model:open="emojiOpen">
                    <PopoverAnchor as-child>
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="inline-flex size-9 cursor-pointer items-center justify-center rounded-lg border-2 border-foreground bg-card text-foreground shadow-2xs transition-all hover:-translate-y-0.5 hover:bg-violet-100 hover:shadow-sm"
                                        @click="emojiOpen = !emojiOpen"
                                    >
                                        <IconMoodSmile class="size-4" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>{{ $t('posts.edit.emoji_picker.search') }}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </PopoverAnchor>
                    <PopoverContent class="w-auto p-0" align="start">
                        <EmojiPicker @select="appendEmoji" />
                    </PopoverContent>
                </Popover>

                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="inline-flex size-9 cursor-pointer items-center justify-center rounded-lg border-2 border-foreground bg-card text-foreground shadow-2xs transition-all hover:-translate-y-0.5 hover:bg-violet-100 hover:shadow-sm"
                                @click="signaturesModal?.open()"
                            >
                                <IconHash class="size-4" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent>{{ $t('posts.edit.signatures') }}</TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="inline-flex size-9 cursor-pointer items-center justify-center rounded-lg border-2 border-foreground bg-card text-foreground shadow-2xs transition-all hover:-translate-y-0.5 hover:bg-violet-100 hover:shadow-sm"
                                @click="emit('open-ai-generate')"
                            >
                                <IconSparkles class="size-4" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent>{{ $t('posts.ai.generate.button_tooltip') }}</TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="inline-flex size-9 cursor-pointer items-center justify-center rounded-lg border-2 border-foreground bg-card text-foreground shadow-2xs transition-all hover:-translate-y-0.5 hover:bg-violet-100 hover:shadow-sm"
                                @click="emit('open-ai-review')"
                            >
                                <IconWriting class="size-4" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent>{{ $t('posts.ai.review.button_tooltip') }}</TooltipContent>
                    </Tooltip>
                </TooltipProvider>

            </div>

            <!-- Per-platform counters (below menu, above textarea) -->
            <div
                v-if="limitsWithUsage.length > 0"
                class="mb-4 flex flex-wrap items-center gap-2"
            >
                <TooltipProvider v-for="limit in limitsWithUsage" :key="limit.platform" :delay-duration="200">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border-2 px-2 py-1 text-[11px] font-bold leading-none tabular-nums shadow-2xs transition-colors"
                                :class="limitClass(limit.state)"
                            >
                                <span class="inline-flex size-3.5 shrink-0 items-center justify-center overflow-hidden rounded-full">
                                    <img :src="getPlatformLogo(limit.platform)" :alt="limit.platform" class="size-full object-cover" />
                                </span>
                                <span>{{ limit.used }}<span class="opacity-60">/{{ limit.maxLength }}</span></span>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>{{ getPlatformLabel(limit.platform) }}</TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>

            <!-- Textarea: borderless, sheet-of-paper feel.
                 Mirror div sits behind to highlight chars beyond the smallest platform limit.
                 Both share identical font/padding/leading/wrap so highlights align with the textarea text. -->
            <div class="relative font-sans text-base leading-[1.7]">
                <div
                    v-if="overflowParts.overflow"
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 whitespace-pre-wrap break-words p-0 font-sans text-base leading-[1.7] text-transparent"
                >
                    <span>{{ overflowParts.fits }}</span><span class="rounded-sm bg-rose-100 text-rose-700">{{ overflowParts.overflow }}</span>
                </div>
                <textarea
                    v-model="content"
                    :readonly="readOnly"
                    :placeholder="readOnly ? '' : $t('posts.edit.caption_placeholder')"
                    class="relative block w-full resize-none border-0 bg-transparent p-0 font-sans text-base leading-[1.7] shadow-none outline-none placeholder:text-foreground/40"
                    style="min-height: 280px; field-sizing: content;"
                />
            </div>

        </div>

        <SignaturesModal ref="signaturesModal" :signatures="signatures" @select="appendSignature" />
        <MediaPickerDialog ref="mediaPickerDialog" @select="addMediaFromGallery" />
        <ImagePreviewDialog ref="lightbox" />
        <AltTextDialog v-model:open="altDialogOpen" :media-item="altDialogItem" @save="onAltTextSave" />
    </div>
</template>
