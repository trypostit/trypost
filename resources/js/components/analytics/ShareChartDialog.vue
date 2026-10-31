<script setup lang="ts">
import {
    IconCopy,
    IconDownload,
} from '@tabler/icons-vue';
import { useResizeObserver } from '@vueuse/core';
import { trans, wTrans } from 'laravel-vue-i18n';
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
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import {
    drawShareCard,
    prepareShareCard,
    SHARE_CARD_SIZE,
    SHARE_TINTS,
    shareCardFile,
    type ShareCardOptions,
    type ShareMetricOption,
    type ShareTint,
} from '@/lib/shareCard';
import { copyImageToClipboard, downloadFile } from '@/lib/utils';
import { storeChunked } from '@/routes/app/media';
import { uploadChunked } from '@/utils/chunkedUpload';

const props = defineProps<{
    subject: string;
    range: string;
    option: ShareMetricOption | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const tint = ref<ShareTint>(SHARE_TINTS[0]);
const posting = ref(false);
const painted = ref(false);
const canvas = useTemplateRef<HTMLCanvasElement>('canvas');

const previewDescription = computed((): string =>
    [props.option?.label, props.option?.figure].filter(Boolean).join(' '),
);

/** The signature, kept up to date as the locale loads (see `.ai/rules/js.md`). */
const mark = wTrans('analytics.share.mark');

const card = computed((): ShareCardOptions | null => {
    if (!props.option) {
        return null;
    }

    return {
        ...props.option,
        tint: tint.value,
        subject: props.subject,
        range: props.range,
        mark: mark.value,
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

/** Whether this opening's preview already failed to draw, so its error is told once, not on every repaint. */
let paintFailed = false;

/** Draws the preview, and says whether it could: not before the dialog has its card and canvas. */
const drawPreview = async (): Promise<boolean> => {
    await nextTick();

    if (!card.value) {
        return false;
    }

    const images = await prepareShareCard(card.value);

    if (!canvas.value || !card.value) {
        return false;
    }

    drawShareCard(canvas.value, card.value, images, previewScale(canvas.value));

    return true;
};

/** Draws the preview; a browser that cannot draw the card stops loading and is told so once. */
const paint = async (): Promise<void> => {
    try {
        if (await drawPreview()) {
            painted.value = true;
        }
    } catch {
        if (!paintFailed) {
            toast.error(trans('analytics.share.export_failed'));
        }

        paintFailed = true;
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

    posting.value = false;
    painted.value = false;
    paintFailed = false;
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
                content: props.option?.caption ?? '',
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
        if (postUpload?.signal === signal) {
            posting.value = false;
        }
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
                :data-shape="option?.shape"
                data-testid="share-chart-preview"
            />

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
