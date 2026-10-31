<script setup lang="ts">
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';

import { activeLocale } from '@/language';

import type { ChartConfig, ChartTooltipDetail } from '.';

const props = withDefaults(
    defineProps<{
        payload?: Record<string, unknown>;
        config?: ChartConfig;
        labelFormatter?: (value: number | Date) => string;
        /** The labelled numbers listed under an entry, such as where it started and what it gained. */
        detailFormatter?: (key: string, value: number) => ChartTooltipDetail[];
        /** A labelled total for the whole point, shown under its heading, such as a day's posts. */
        totalFormatter?: (x: number | Date) => ChartTooltipDetail | null;
        x?: number | Date;
    }>(),
    { payload: () => ({}), config: () => ({}) },
);

const entries = computed(() =>
    Object.entries(props.config).flatMap(([key, item]) => {
        const value = props.payload[key];
        return typeof value === 'number'
            ? [
                  {
                      key,
                      item,
                      value,
                      details: props.detailFormatter?.(key, value) ?? [],
                  },
              ]
            : [];
    }),
);

const total = computed((): ChartTooltipDetail | null => {
    if (props.x === undefined || !props.totalFormatter) {
        return null;
    }

    return props.totalFormatter(props.x);
});

const DETAIL_TONES: Record<NonNullable<ChartTooltipDetail['tone']>, string> = {
    up: 'text-success-text',
    down: 'text-destructive-text',
};
</script>

<template>
    <div
        class="min-w-52 overflow-hidden rounded-xl bg-popover text-xs text-popover-foreground shadow-md ring-1 ring-foreground/5 dark:ring-border"
        data-testid="analytics-chart-tooltip"
    >
        <p
            v-if="x !== undefined"
            class="border-b border-border px-3 py-2 font-medium text-foreground"
        >
            {{ labelFormatter ? labelFormatter(x) : x }}
        </p>
        <div
            v-if="total"
            class="flex items-center justify-between gap-6 border-b border-border px-3 py-2 font-medium text-foreground"
            data-testid="analytics-tooltip-total"
        >
            <span>{{ total.label }}</span>
            <span class="tabular-nums">{{ total.value }}</span>
        </div>
        <div class="grid gap-2 px-3 py-2.5">
            <div v-for="entry in entries" :key="entry.key" class="grid gap-0.5">
                <div class="flex items-center justify-between gap-6">
                    <span
                        class="inline-flex min-w-0 items-center gap-2 text-muted-foreground"
                    >
                        <ChannelAvatar
                            v-if="entry.item.avatar"
                            :platform="entry.item.avatar.platform"
                            :name="entry.item.avatar.name"
                            :src="entry.item.avatar.src"
                            :verified="entry.item.avatar.verified ?? null"
                            :size="20"
                            ring="popover"
                            data-testid="analytics-tooltip-avatar"
                        />
                        <span class="truncate">{{ entry.item.label }}</span>
                    </span>
                    <span
                        class="font-medium text-foreground tabular-nums"
                        data-testid="analytics-tooltip-value"
                        >{{ entry.value.toLocaleString(activeLocale) }}</span
                    >
                </div>
                <dl
                    v-if="entry.details.length > 0"
                    class="mt-1 grid gap-1 border-t border-border pt-2"
                    data-testid="analytics-tooltip-detail"
                >
                    <div
                        v-for="detail in entry.details"
                        :key="detail.label"
                        class="flex items-center justify-between gap-6"
                    >
                        <dt class="text-muted-foreground">{{ detail.label }}</dt>
                        <dd
                            class="font-medium text-foreground tabular-nums"
                            :class="detail.tone && DETAIL_TONES[detail.tone]"
                        >
                            {{ detail.value }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</template>
