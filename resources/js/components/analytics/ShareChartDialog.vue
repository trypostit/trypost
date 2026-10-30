<script setup lang="ts">
import { IconCheck, IconCopy, IconDownload } from '@tabler/icons-vue';
import { useResizeObserver } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, shallowRef, useTemplateRef, watch } from 'vue';
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
import date from '@/date';
import {
    drawShareCard,
    loadShareFonts,
    loadShareLogos,
    SHARE_CARD_SIZE,
    SHARE_TINTS,
    shareCardFile,
    type ShareCardOptions,
    type ShareMetricOption,
    type ShareTint,
} from '@/lib/insightsShareCard';
import { storeChunked } from '@/routes/app/media';
import { uploadChunked } from '@/utils/chunkedUpload';

const props = defineProps<{
    variant: 'chart' | 'summary';
    subject: string;
    range: string;
    logos: string[];
    options: ShareMetricOption[];
    initialKey?: string;
}>();

const open = defineModel<boolean>('open', { default: false });


const tint = ref<ShareTint>(SHARE_TINTS[0]);

/** Each swatch shows the background with the accent drawn as a tiny bar chart. */
const SWATCH_BARS = [8, 14, 11];
const selectedKey = ref<string>('');
const copied = ref(false);
const posting = ref(false);
const painted = ref(false);
const logoImages = shallowRef<HTMLImageElement[]>([]);
const canvas = useTemplateRef<HTMLCanvasElement>('canvas');

const selected = computed(
    (): ShareMetricOption | undefined =>
        props.options.find((option) => option.key === selectedKey.value) ??
        props.options[0],
);

const cardOptions = (): ShareCardOptions | null => {
    const option = selected.value;

    if (!option) {
        return null;
    }

    return {
        variant: props.variant,
        tint: tint.value,
        subject: props.subject,
        metric: option.label,
        range: props.range,
        figure: option.figure,
        change: option.change,
        comparison: option.comparison,
        points: option.points,
        shape: option.shape,
        tiles: option.tiles,
        logos: logoImages.value,
        tick: option.tick,
        mark: trans('analytics.share.mark'),
    };
};

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
    logoImages.value = await loadShareLogos(props.logos);

    const options = cardOptions();

    if (canvas.value && options) {
        drawShareCard(canvas.value, options, previewScale(canvas.value));
        painted.value = true;
    }
};

watch(open, (isOpen) => {
    if (isOpen) {
        selectedKey.value = props.initialKey ?? props.options[0]?.key ?? '';
        copied.value = false;
        posting.value = false;
        painted.value = false;
        paint();
    }
});

useResizeObserver(canvas, () => paint());

watch([tint, selectedKey], () => {
    copied.value = false;
    paint();
});

const filename = (): string =>
    `trypost-${(selected.value?.key ?? 'insights').replace(/_/g, '-')}-${date.userNow().format('YYYY-MM-DD')}.png`;

const exportFile = (): Promise<File> | null => {
    const options = cardOptions();

    return options ? shareCardFile(options, filename()) : null;
};

const download = async (): Promise<void> => {
    try {
        const file = await exportFile();

        if (!file) {
            return;
        }

        const url = URL.createObjectURL(file);
        const link = document.createElement('a');

        link.href = url;
        link.download = file.name;
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch {
        toast.error(trans('analytics.share.export_failed'));
    }
};

/**
 * The clipboard write starts right away, inside the click, with the image as a
 * promise: Safari refuses a write that only starts after the PNG is encoded.
 */
const copyImage = async (): Promise<void> => {
    try {
        if (!('clipboard' in navigator) || typeof ClipboardItem === 'undefined') {
            throw new Error('Clipboard unavailable');
        }

        const image = exportFile();

        if (!image) {
            throw new Error('Nothing to copy');
        }

        await navigator.clipboard.write([
            new ClipboardItem({ 'image/png': image.then((file) => file) }),
        ]);
        copied.value = true;
    } catch {
        toast.error(trans('analytics.share.copy_failed'));
    }
};

const chooseTint = (swatch: ShareTint): void => {
    tint.value = swatch;
};

const closeDialog = (): void => {
    open.value = false;
};

const createPost = async (): Promise<void> => {
    posting.value = true;

    try {
        const file = await exportFile();

        if (!file) {
            throw new Error('Nothing to share');
        }

        const uploaded = await uploadChunked({ file, url: storeChunked.url() });

        if (!uploaded.id || !uploaded.url) {
            throw new Error('Incomplete upload');
        }

        open.value = false;
        openPostComposer({
            draft: {
                content: selected.value?.caption ?? '',
                media: [
                    {
                        id: uploaded.id,
                        path: uploaded.path,
                        url: uploaded.url,
                        type: uploaded.type,
                        mime_type: uploaded.mime_type ?? file.type,
                        original_filename: uploaded.original_filename ?? file.name,
                        size: uploaded.size ?? file.size,
                        meta: uploaded.meta,
                        upload_token: uploaded.upload_token ?? null,
                        created_at: uploaded.created_at,
                    },
                ],
                scheduled_at: null,
                label_ids: [],
            },
        });
    } catch {
        toast.error(trans('analytics.share.post_failed'));
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

                <div class="grid gap-2">
                    <span class="text-sm font-medium text-foreground">{{
                        $t('analytics.share.color')
                    }}</span>
                    <div
                        class="flex h-9 items-center gap-2"
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
                            class="flex h-9 w-12 cursor-pointer items-end justify-center gap-0.5 rounded-lg pb-2 transition-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            :class="
                                tint.key === swatch.key
                                    ? 'border-2 border-foreground'
                                    : 'border border-foreground/15 hover:border-foreground/40'
                            "
                            :style="{ backgroundColor: swatch.ground }"
                            :data-testid="`share-chart-color-${swatch.key}`"
                            @click="chooseTint(swatch)"
                        >
                            <span
                                v-for="height in SWATCH_BARS"
                                :key="height"
                                class="w-1 rounded-full"
                                :style="{
                                    height: `${height}px`,
                                    backgroundColor: swatch.strong,
                                }"
                                aria-hidden="true"
                            />
                        </button>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="closeDialog">
                    {{ $t('common.cancel') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-testid="share-chart-download"
                    @click="download"
                >
                    <IconDownload aria-hidden="true" />
                    {{ $t('analytics.share.download') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-testid="share-chart-copy"
                    @click="copyImage"
                >
                    <component
                        :is="copied ? IconCheck : IconCopy"
                        aria-hidden="true"
                    />
                    {{
                        copied
                            ? $t('analytics.share.copied')
                            : $t('analytics.share.copy')
                    }}
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
