<script setup lang="ts">
import { computed } from 'vue';

type SegmentKind = 'text' | 'link' | 'tag';

interface Segment {
    kind: SegmentKind;
    value: string;
}

const props = withDefaults(
    defineProps<{
        text: string;
        overlay?: boolean;
        tone?: 'brand' | 'info';
        hostOnly?: boolean;
        tags?: 'link' | 'bare' | 'plain' | 'muted';
        links?: boolean;
    }>(),
    {
        overlay: false,
        tone: 'brand',
        hostOnly: false,
        tags: 'link',
        links: true,
    },
);

const TOKEN =
    /(^|[^\p{L}\p{N}_])(https?:\/\/\S*[^\s.,!?;:)'"]|www\.\S*[^\s.,!?;:)'"]|[#@][\p{L}\p{N}_]+)/gu;

const display = (kind: SegmentKind, value: string): string => {
    if (kind === 'link' && props.hostOnly) {
        return value.replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/$/, '');
    }

    if (kind === 'tag' && props.tags === 'bare' && value.startsWith('#')) {
        return value.slice(1);
    }

    return value;
};

const segments = computed((): Segment[] => {
    const result: Segment[] = [];
    let cursor = 0;

    for (const match of props.text.matchAll(TOKEN)) {
        const start = (match.index ?? 0) + match[1].length;
        const kind: SegmentKind = /^[#@]/.test(match[2]) ? 'tag' : 'link';

        if (start > cursor) {
            result.push({ kind: 'text', value: props.text.slice(cursor, start) });
        }

        result.push({ kind, value: display(kind, match[2]) });
        cursor = start + match[2].length;
    }

    if (cursor < props.text.length) {
        result.push({ kind: 'text', value: props.text.slice(cursor) });
    }

    return result;
});

const linkClass = computed((): string =>
    props.tone === 'info'
        ? 'text-info underline underline-offset-2'
        : 'text-primary-text underline underline-offset-2',
);

const segmentClass = (kind: SegmentKind): string => {
    if (kind === 'text') {
        return '';
    }

    if (props.overlay) {
        return kind === 'tag' ? 'font-semibold' : '';
    }

    if (kind === 'link' && !props.links) {
        return '';
    }

    if (kind === 'tag' && props.tags === 'plain') {
        return '';
    }

    if (kind === 'tag' && props.tags === 'muted') {
        return 'text-muted-foreground underline underline-offset-2';
    }

    return linkClass.value;
};
</script>

<template>
    <span class="break-words whitespace-pre-wrap"
        ><span
            v-for="(segment, index) in segments"
            :key="index"
            :class="segmentClass(segment.kind)"
            >{{ segment.value }}</span
        ></span
    >
</template>
