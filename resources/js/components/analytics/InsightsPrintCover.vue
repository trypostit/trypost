<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { nextTick, onMounted } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import date from '@/date';

defineProps<{
    title: string;
    logoUrl: string | null;
    generatedAt: string;
    range: { start: string; end: string };
    previousRange: { start: string; end: string };
}>();

declare global {
    interface Window {
        pdfReady?: boolean;
    }
}

/** What the charts need to finish drawing after they mount (unovis draws on the next frames). */
const CHART_SETTLE_MS = 1200;

const span = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;

/** How long one image may take before the report prints without it. */
const IMAGE_TIMEOUT_MS = 10000;

/** A broken image (an expired network preview) leaves its place empty on paper. */
const hideBroken = (image: HTMLImageElement): void => {
    image.style.visibility = 'hidden';
};

/**
 * Every image loaded, or given up on. Lazy images would never load: Chromium
 * prints without scrolling, so they are asked for at once.
 */
const imagesLoaded = (): Promise<unknown> =>
    Promise.all(
        [...document.images].map((image) => {
            image.loading = 'eager';

            if (image.complete) {
                if (image.naturalWidth === 0) {
                    hideBroken(image);
                }

                return Promise.resolve();
            }

            return new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener(
                    'error',
                    () => {
                        hideBroken(image);
                        resolve(null);
                    },
                    { once: true },
                );
                window.setTimeout(resolve, IMAGE_TIMEOUT_MS);
            });
        }),
    );

/** Tells Chromium (`waitUntilReady`) the report is drawn: fonts, images and charts. */
onMounted(async () => {
    await nextTick();
    await document.fonts.ready;
    await imagesLoaded();
    await new Promise((resolve) => window.setTimeout(resolve, CHART_SETTLE_MS));
    window.pdfReady = true;
});
</script>

<template>
    <div
        class="flex w-[1024px] flex-col gap-6 bg-background"
        data-testid="insights-print"
    >
        <Head :title="title" />
        <header
            class="flex items-start justify-between gap-6 border-b border-border pb-6"
        >
            <div class="flex min-w-0 flex-col gap-2">
                <p
                    class="text-xs font-semibold tracking-wider text-primary-text uppercase"
                >
                    {{ $t('analytics.insights.export.pdf.cover.eyebrow') }}
                </p>
                <h1 class="font-heading text-3xl font-medium text-foreground">
                    {{ title }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        $t('analytics.ranges.compared_to', {
                            current: span(range),
                            previous: span(previousRange),
                        })
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ $t('analytics.insights.export.generated_at') }}
                    {{ generatedAt }}
                </p>
            </div>
            <img
                v-if="logoUrl"
                :src="logoUrl"
                alt=""
                class="max-h-14 max-w-48 object-contain"
            />
            <AppLogo v-else class="text-2xl" />
        </header>
        <slot />
    </div>
</template>
