<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { nextTick, onMounted, ref, watch } from 'vue';

import PreviewText from '@/components/posts/previews/PreviewText.vue';
import { clamp } from '@/lib/helpers';

const props = withDefaults(
    defineProps<{
        text: string;
        lines: number;
        moreKey: string;
        tone?: 'brand' | 'info';
        hostOnly?: boolean;
        tags?: 'link' | 'bare' | 'plain' | 'muted';
        links?: boolean;
    }>(),
    {
        tone: 'brand',
        hostOnly: false,
        tags: 'link',
        links: true,
    },
);

const box = ref<HTMLElement | null>(null);
const more = ref<HTMLElement | null>(null);
const isClamped = ref(false);
const moreOffset = ref(0);

/**
 * Like LinkedIn's feed, the text stops after a number of lines and "… more"
 * sits right after the last visible character, covering the end of that line
 * when it is full.
 */
const measure = async (): Promise<void> => {
    if (!box.value) {
        return;
    }

    isClamped.value = box.value.scrollHeight > box.value.clientHeight + 1;

    if (!isClamped.value) {
        return;
    }

    await nextTick();

    const bounds = box.value.getBoundingClientRect();
    const lineHeight = parseFloat(getComputedStyle(box.value).lineHeight);
    const range = document.createRange();
    range.selectNodeContents(box.value);

    const lastLineEnd = [...range.getClientRects()]
        .filter(
            (rect) =>
                rect.width > 0 &&
                rect.top >= bounds.bottom - lineHeight - 1 &&
                rect.bottom <= bounds.bottom + 1,
        )
        .reduce((end, rect) => Math.max(end, rect.right - bounds.left), 0);

    moreOffset.value = clamp(
        lastLineEnd,
        0,
        bounds.width - (more.value?.offsetWidth ?? 0),
    );
};

useResizeObserver(box, measure);

onMounted(async () => {
    await document.fonts.ready;
    await measure();
});

watch(
    () => props.text,
    async () => {
        await nextTick();
        await measure();
    },
);
</script>

<template>
    <div class="relative">
        <div
            ref="box"
            class="overflow-hidden"
            :style="{ maxHeight: `${lines}lh` }"
            data-testid="preview-clamped-text"
        >
            <PreviewText
                :text="text"
                :tone="tone"
                :host-only="hostOnly"
                :tags="tags"
                :links="links"
            />
        </div>
        <span
            v-if="isClamped"
            ref="more"
            class="absolute bottom-0 bg-card whitespace-nowrap"
            :style="{ left: `${moreOffset}px` }"
            data-testid="preview-more"
            >… <span class="text-muted-foreground">{{ $t(moreKey) }}</span></span
        >
    </div>
</template>
