<script setup lang="ts">
import {
    IconCopy,
    IconDownload,
} from '@tabler/icons-vue';
import { useResizeObserver } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import {
    drawShareCard,
    prepareShareCard,
    SHARE_CARD_SIZE,
    SHARE_TINTS,
    shareCardFile,
    type ShareCardOptions,
    type ShareMetricKey,
    type ShareMetricOption,
    type ShareTint,
} from '@/lib/insightsShareCard';
import { copyImageToClipboard, downloadFile } from '@/lib/utils';
import { storeChunked } from '@/routes/app/media';
import { uploadChunked } from '@/utils/chunkedUpload';

const props = defineProps<{
    subject: string;
    range: string;
    options: ShareMetricOption[];
    initialKey?: ShareMetricKey;
}>();

const open = defineModel<boolean>('open', { default: false });

const tint = ref<ShareTint>(SHARE_TINTS[0]);
/** The metric asked for; `selected` falls back to the first when the card cannot draw it. */
const selectedKey = ref<ShareMetricKey>();
const posting = ref(false);
const painted = ref(false);
const canvas = useTemplateRef<HTMLCanvasElement>('canvas');

const selected = computed(
    (): ShareMetricOption | undefined =>
        props.options.find((option) => option.key === selectedKey.value) ??
        props.options[0],
);

/** The metric picker's value: always one of the options, even when the one asked for is not. */
const metricKey = computed({
    get: (): ShareMetricKey | undefined => selected.value?.key,
    set: (key: ShareMetricKey | undefined): void => {
        selectedKey.value = key;
    },
});

const previewDescription = computed((): string =>
    [selected.value?.label, selected.value?.figure].filter(Boolean).join(' '),
);

const card = computed((): ShareCardOptions | null => {
    if (!selected.value) {
        return null;
    }

    return {
        ...selected.value,
        tint: tint.value,
        subject: props.subject,
        range: props.range,
        mark: trans('analytics.share.mark'),
    };
});

/** Below this the preview would get no pixels while its canvas is not laid out yet. */
const MIN_PREVIEW_SCALE = 0.25;

/**
 * The preview is drawn at the size it is shown, times the screen density, so
 * the browser never shrinks a bigger card into it and blurs the text.
 */
const previewScale = (preview: HTMLCanvasElement): number => {
    const shownWidth = preview.clientWidth * window.devicePixelRatio;

    return Math.max(shownWidth / SHARE_CARD_SIZE.width, MIN_PREVIEW_SCALE);
};

const paint = async (): Promise<void> => {
    await nextTick();

    if (!card.value) {
        return;
    }

    const images = await prepareShareCard(card.value);

    if (!canvas.value || !card.value) {
        return;
    }

    drawShareCard(canvas.value, card.value, images, previewScale(canvas.value));
    painted.value = true;
};

/** The image uploading for Create post, stopped when the dialog closes so the composer never opens later. */
let postUpload: AbortController | null = null;

watch(open, (isOpen) => {
    if (!isOpen) {
        postUpload?.abort();

        return;
    }

    selectedKey.value = props.initialKey;
    posting.value = false;
    painted.value = false;
    paint();
});

useResizeObserver(canvas, () => paint());

watch(card, () => {
    paint();
});

/** The card as a PNG file, at download resolution. */
const exportFile = async (): Promise<File> => {
    if (!card.value) {
        throw new Error('Nothing to share');
    }

    return shareCardFile(card.value);
};

const download = async (): Promise<void> => {
    try {
        downloadFile(await exportFile());
    } catch {
        toast.error(trans('analytics.share.export_failed'));
    }
};

const copyImage = async (): Promise<void> => {
    try {
        await copyImageToClipboard(exportFile());
        toast.success(trans('analytics.share.copied'));
    } catch {
        toast.error(trans('analytics.share.copy_failed'));
    }
};

const chooseTint = (swatch: ShareTint): void => {
    tint.value = swatch;
};

const createPost = async (): Promise<void> => {
    postUpload = new AbortController();
    const { signal } = postUpload;
    posting.value = true;

    try {
        const media = await uploadChunked({
            file: await exportFile(),
            url: storeChunked.url(),
            signal,
        });

        open.value = false;
        openPostComposer({
            draft: {
                content: selected.value?.caption ?? '',
                media: [media],
                scheduled_at: null,
                label_ids: [],
            },
        });
    } catch {
        if (!signal.aborted) {
            toast.error(trans('analytics.share.post_failed'));
        }
    } finally {
        posting.value = false;
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl" data-testid="share-chart-dialog">
            <DialogHeader>
                <DialogTitle>{{ $t('analytics.share.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('analytics.share.description') }}
                </DialogDescription>
            </DialogHeader>

            <canvas
                ref="canvas"
                class="aspect-video w-full rounded-lg"
                :class="{ 'animate-pulse bg-muted': !painted }"
                role="img"
                :aria-label="previewDescription"
                :data-state="painted ? 'ready' : 'loading'"
                :data-shape="selected?.shape"
                data-testid="share-chart-preview"
            />

            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <div v-if="options.length > 1" class="grid min-w-44 gap-2">
                    <Label for="share-chart-metric">{{
                        $t('analytics.share.metric')
                    }}</Label>
                    <Select v-model="metricKey">
                        <SelectTrigger
                            id="share-chart-metric"
                            class="h-8"
                            data-testid="share-chart-metric"
                        >
                            <SelectValue>{{ selected?.label }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in options"
                                :key="option.key"
                                :value="option.key"
                                :data-testid="`share-chart-metric-${option.key}`"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid w-full gap-2">
                    <span class="text-sm font-medium text-foreground">{{
                        $t('analytics.share.color')
                    }}</span>
                    <div
                        class="grid grid-cols-8 gap-2.5 sm:grid-cols-16"
                        role="radiogroup"
                        :aria-label="$t('analytics.share.color')"
                    >
                        <button
                            v-for="swatch in SHARE_TINTS"
                            :key="swatch.key"
                            type="button"
                            role="radio"
                            :aria-checked="tint.key === swatch.key"
                            :aria-label="$t(`analytics.share.colors.${swatch.key}`)"
                            class="aspect-square w-full cursor-pointer rounded-md bg-(--swatch) inset-ring inset-ring-black/6 ring-offset-background transition-[box-shadow] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring aria-checked:inset-ring-0 aria-checked:ring-[1.5px] aria-checked:ring-offset-2 aria-checked:ring-(--swatch-ring)"
                            :style="{
                                '--swatch': swatch.ground,
                                '--swatch-ring': swatch.strong,
                            }"
                            :data-testid="`share-chart-color-${swatch.key}`"
                            @click="chooseTint(swatch)"
                        />
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    class="max-sm:flex-1"
                    data-testid="share-chart-download"
                    @click="download"
                >
                    <IconDownload aria-hidden="true" />
                    {{ $t('analytics.share.download') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    class="max-sm:flex-1"
                    data-testid="share-chart-copy"
                    @click="copyImage"
                >
                    <IconCopy aria-hidden="true" />
                    {{ $t('analytics.share.copy') }}
                </Button>
                <Button
                    type="button"
                    :loading="posting"
                    data-testid="share-chart-post"
                    @click="createPost"
                >
                    {{ $t('analytics.share.create_post') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
