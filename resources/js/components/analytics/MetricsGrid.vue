<script setup lang="ts">
import {
    IconActivity,
    IconBookmark,
    IconChartBar,
    IconClick,
    IconClock,
    IconEye,
    IconHeart,
    IconMessage,
    IconPlayerPlay,
    IconPointer,
    IconQuote,
    IconRepeat,
    IconShare,
    IconThumbUp,
    IconUserPlus,
    IconUsers,
} from '@tabler/icons-vue';
import type { FunctionalComponent } from 'vue';

import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { formatNumber } from '@/lib/utils';

interface MetricItem {
    label: string;
    value: number;
}

withDefaults(
    defineProps<{
        metrics: MetricItem[];
        loading?: boolean;
        emptyLabel?: string;
        skeletonCount?: number;
    }>(),
    {
        loading: false,
        emptyLabel: '',
        skeletonCount: 8,
    },
);

// Loose keyword match. Backend metric labels are derived from raw API names
// (English regardless of UI locale), so substring matching covers things
// like "Profile views", "Video views", "Total likes", etc. without a
// per-platform mapping.
type MetricTone = { bg: string };

const TONES = {
    eye: { bg: 'bg-violet-100' },
    heart: { bg: 'bg-rose-100' },
    chat: { bg: 'bg-sky-100' },
    share: { bg: 'bg-emerald-100' },
    star: { bg: 'bg-amber-100' },
    play: { bg: 'bg-fuchsia-100' },
    users: { bg: 'bg-cyan-100' },
    default: { bg: 'bg-muted' },
} satisfies Record<string, MetricTone>;

interface Mapping {
    icon: FunctionalComponent;
    tone: MetricTone;
}

// Multi-locale keyword matching. Keys are lowercased substrings of metric labels
// across English, Portuguese, and Spanish so icons render correctly regardless
// of the active locale. Order matters — earlier rules win.
const MATCHES: Array<{ keys: string[]; mapping: Mapping }> = [
    {
        keys: [
            'view',
            'visualiz',
            'vista',
            'impress',
            'impres',
            'reach',
            'alcanc',
        ],
        mapping: { icon: IconEye, tone: TONES.eye },
    },
    {
        keys: [
            'like',
            'curtid',
            'me gust',
            'favour',
            'favorit',
            'reaction',
            'love',
        ],
        mapping: { icon: IconHeart, tone: TONES.heart },
    },
    {
        keys: ['reply', 'replies', 'respost', 'respue', 'comment', 'coment'],
        mapping: { icon: IconMessage, tone: TONES.chat },
    },
    {
        keys: ['repost', 'reblog', 'retweet'],
        mapping: { icon: IconRepeat, tone: TONES.share },
    },
    {
        keys: ['quote', 'cita'],
        mapping: { icon: IconQuote, tone: TONES.share },
    },
    {
        keys: ['save', 'salv', 'guardad', 'bookmark'],
        mapping: { icon: IconBookmark, tone: TONES.star },
    },
    {
        keys: [
            'watch',
            'duration',
            'duraç',
            'duración',
            'minut',
            'tempo',
            'time',
        ],
        mapping: { icon: IconClock, tone: TONES.play },
    },
    {
        keys: ['video', 'vídeo', 'play'],
        mapping: { icon: IconPlayerPlay, tone: TONES.play },
    },
    {
        keys: [
            'follower',
            'seguidor',
            'subscriber',
            'inscrit',
            'suscrip',
            'audience',
        ],
        mapping: { icon: IconUserPlus, tone: TONES.users },
    },
    {
        keys: ['profile', 'perfil', 'visit'],
        mapping: { icon: IconUsers, tone: TONES.users },
    },
    {
        keys: ['click', 'cliqu', 'clic'],
        mapping: { icon: IconClick, tone: TONES.share },
    },
    {
        keys: ['tap', 'pointer'],
        mapping: { icon: IconPointer, tone: TONES.share },
    },
    {
        keys: ['engage', 'engaj', 'engagement', 'interacti', 'interaç'],
        mapping: { icon: IconActivity, tone: TONES.heart },
    },
    { keys: ['thumb'], mapping: { icon: IconThumbUp, tone: TONES.share } },
    {
        keys: ['shar', 'compart'],
        mapping: { icon: IconShare, tone: TONES.share },
    },
];

const matchMetric = (label: string): Mapping => {
    const haystack = label.toLowerCase();
    for (const { keys, mapping } of MATCHES) {
        if (keys.some((k) => haystack.includes(k))) return mapping;
    }
    return { icon: IconChartBar, tone: TONES.default };
};
</script>

<template>
    <!-- Loading -->
    <div
        v-if="loading"
        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
    >
        <Card v-for="i in skeletonCount" :key="i">
            <CardContent class="p-6">
                <div class="flex items-start justify-between gap-3">
                    <Skeleton class="h-4 w-24" />
                    <Skeleton class="size-10 rounded-2xl" />
                </div>
                <Skeleton class="mt-3 h-8 w-32" />
            </CardContent>
        </Card>
    </div>

    <!-- Metrics -->
    <div
        v-else-if="metrics.length > 0"
        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
    >
        <Card v-for="metric in metrics" :key="metric.label">
            <CardContent class="p-6">
                <div class="flex items-start justify-between gap-3">
                    <p
                        class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                    >
                        {{ metric.label }}
                    </p>
                    <span
                        :class="[
                            'inline-flex size-10 shrink-0 items-center justify-center rounded-xl border border-border shadow-xs',
                            matchMetric(metric.label).tone.bg,
                        ]"
                    >
                        <component
                            :is="matchMetric(metric.label).icon"
                            class="size-5 text-foreground"
                            stroke-width="2"
                        />
                    </span>
                </div>
                <p
                    class="mt-3 text-3xl font-bold tracking-tight text-foreground tabular-nums"
                >
                    {{ formatNumber(metric.value) }}
                </p>
            </CardContent>
        </Card>
    </div>

    <!-- No Data -->
    <div
        v-else
        class="flex h-full items-center justify-center text-sm font-medium text-foreground/60"
    >
        {{ emptyLabel }}
    </div>
</template>
