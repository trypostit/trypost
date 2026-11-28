<script setup lang="ts">
import { computed } from 'vue';

import AdminEmptyState from '@/components/admin/shared/AdminEmptyState.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent, MISSING_VALUE, percentOf } from '@/lib/utils';
import { type WelcomeOptionArt, welcomeOptionArt } from '@/lib/welcomeOptions';
import type { AdminBreakdownRow } from '@/types/admin/billing';

/** A tile as Welcome draws it, or a social network's own logo, as the rest of the app shows it. */
export type BreakdownArt = WelcomeOptionArt & { platform?: string };

/**
 * One Welcome answer split out: how many gave each option, as a bar against
 * the most given, and how many of them subscribed.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        subtitle: string;
        rows: AdminBreakdownRow[];
        /** The options' tiles, as Welcome draws them; none draws no tile. */
        art?: Record<string, BreakdownArt>;
        testId: string;
        /** Who answered: each answer's share is of them. */
        answered: number;
        /** Off where every answer subscribed by how it is asked, so the share tells nothing. */
        showsSubscribed?: boolean;
    }>(),
    { showsSubscribed: true },
);

const hasRows = computed(() => filled(props.rows));

const most = computed(() => Math.max(1, ...props.rows.map((row) => row.count)));

const shareOf = (count: number): string => {
    if (props.answered === 0) {
        return MISSING_VALUE;
    }

    return formatPercent(percentOf(count, props.answered), 1);
};

const barWidth = (count: number): string => `${(count / most.value) * 100}%`;

const artFor = (value: string): BreakdownArt | null => {
    if (filled(props.art)) {
        return welcomeOptionArt(props.art, value);
    }

    return null;
};

/** A network's answer shows the network's logo instead of a tile. */
const platformOf = (art: BreakdownArt | null): string | null => {
    const platform = art?.platform;

    if (filled(platform)) {
        return platform;
    }

    return null;
};

const items = computed(() =>
    props.rows.map((row) => {
        const art = artFor(row.value);

        return { ...row, art, platform: platformOf(art) };
    }),
);
</script>

<template>
    <AnalyticsSection :title="title" :subtitle="subtitle" flush>
        <AdminEmptyState v-if="!hasRows" variant="section">No answers in this period.</AdminEmptyState>
        <template v-else>
            <div
                class="flex items-center gap-3 border-t border-border px-4 pt-3 pb-1 text-xs text-muted-foreground sm:px-5"
            >
                <span class="flex-1">Answer</span>
                <span class="w-12 text-right">People</span>
                <span class="w-14 text-right">Share</span>
                <span v-if="showsSubscribed" class="w-16 text-right">Subscribed</span>
            </div>
            <ul class="flex flex-col pb-2" :data-testid="testId">
                <li
                    v-for="row in items"
                    :key="row.value"
                    class="flex items-center gap-3 px-4 py-1.5 sm:px-5"
                >
                    <PlatformLogo v-if="row.platform" :platform="row.platform" size="xs" />
                    <span
                        v-else-if="row.art"
                        class="inline-flex size-6 shrink-0 items-center justify-center rounded-md text-sm"
                        :class="row.art.tint"
                    >
                        <img v-if="row.art.logo" :src="row.art.logo" alt="" class="size-4 object-contain" />
                        <component
                            :is="row.art.icon"
                            v-else-if="row.art.icon"
                            class="size-4 text-neutral-900"
                        />
                        <span v-else aria-hidden="true">{{ row.art.emoji }}</span>
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span class="truncate text-sm text-foreground">{{ row.label }}</span>
                        <span class="h-1.5 overflow-hidden rounded-full bg-muted">
                            <span
                                class="block h-full rounded-full bg-primary-text"
                                :style="{ width: barWidth(row.count) }"
                            />
                        </span>
                    </span>
                    <span class="w-12 text-right text-sm font-medium text-foreground tabular-nums">{{
                        formatFullNumber(row.count)
                    }}</span>
                    <span class="w-14 text-right text-sm text-muted-foreground tabular-nums">{{
                        shareOf(row.count)
                    }}</span>
                    <span
                        v-if="showsSubscribed"
                        class="w-16 text-right text-sm text-muted-foreground tabular-nums"
                        >{{ formatPercent(row.subscribed_rate, 1) }}</span
                    >
                </li>
            </ul>
        </template>
    </AnalyticsSection>
</template>
