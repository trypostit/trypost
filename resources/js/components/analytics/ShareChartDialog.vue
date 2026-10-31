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
    loadShareFonts,
    SHARE_CARD_SIZE,
    SHARE_TINTS,
    shareCardFile,
    type ShareCardOptions,
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
    initialKey?: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const tint = ref<ShareTint>(SHARE_TINTS[0]);
const selectedKey = ref<string>('');
const posting = ref(false);
const painted = ref(false);
const canvas = useTemplateRef<HTMLCanvasElement>('canvas');

const selected = computed(
    (): ShareMetricOption | undefined =>
        props.options.find((option) => option.key === selectedKey.value) ??
        props.options[0],
);

const cardOptions = (): ShareCardOptions | null =>
    selected.value
        ? {
              ...selected.value,
              tint: tint.value,
              subject: props.subject,
              range: props.range,
              mark: trans('analytics.share.mark'),
          }
        : null;

/**
 * The preview is drawn at the size it is shown, times the screen density, so
 * the browser never shrinks a bigger card into it and blurs the text.
 */
const previewScale = (element: HTMLCanvasElement): number =>
    Math.max(
        (element.clientWidth * window.devicePixelRatio) / SHARE_CARD_SIZE.width,
        0.25,
    );

const paint = async (): Promise<void> => {
    await nextTick();
    await loadShareFonts();

    const options = cardOptions();

    if (canvas.value && options) {
        drawShareCard(canvas.value, options, previewScale(canvas.value));
        painted.value = true;
    }
};

/** The image uploading for Create post, stopped when the dialog closes so the composer never opens later. */
let postUpload: AbortController | null = null;

watch(open, (isOpen) => {
    if (!isOpen) {
        postUpload?.abort();

        return;
    }

    selectedKey.value =
        props.options.find((option) => option.key === props.initialKey)?.key ??
        props.options[0]?.key ??
        '';
    posting.value = false;
    painted.value = false;
    paint();
});

useResizeObserver(canvas, () => paint());

watch([tint, selectedKey], () => {
    paint();
});

/** The card as a PNG file, at download resolution. */
const exportFile = (): Promise<File> => {
    const options = cardOptions();

    return options
        ? shareCardFile(options)
        : Promise.reject(new Error('Nothing to share'));
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
                class="w-full rounded-lg"
                :style="{ aspectRatio: `${SHARE_CARD_SIZE.width} / ${SHARE_CARD_SIZE.height}` }"
                role="img"
                :aria-label="`${selected?.label ?? ''} ${selected?.figure ?? ''}`"
                :data-state="painted ? 'ready' : 'loading'"
                :data-shape="selected?.shape"
                data-testid="share-chart-preview"
            />

            <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
                <div v-if="options.length > 1" class="grid min-w-44 gap-2">
                    <Label for="share-chart-metric">{{
                        $t('analytics.share.metric')
                    }}</Label>
                    <Select v-model="selectedKey">
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
                            class="aspect-square w-full cursor-pointer rounded-md transition-[box-shadow] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                            :style="{
                                backgroundColor: swatch.ground,
                                boxShadow:
                                    tint.key === swatch.key
                                        ? `0 0 0 2px var(--background), 0 0 0 3.5px ${swatch.strong}`
                                        : 'inset 0 0 0 1px rgb(0 0 0 / 0.06)',
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
