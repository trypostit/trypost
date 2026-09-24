<script setup lang="ts">
import { computed } from 'vue';

import { activeLocale } from '@/language';

import type { ChartConfig } from '.';

const props = withDefaults(
    defineProps<{
        payload?: Record<string, unknown>;
        config?: ChartConfig;
        labelFormatter?: (value: number | Date) => string;
        x?: number | Date;
    }>(),
    { payload: () => ({}), config: () => ({}) },
);

const entries = computed(() =>
    Object.entries(props.config).flatMap(([key, item]) => {
        const value = props.payload[key];
        return typeof value === 'number' ? [{ key, item, value }] : [];
    }),
);
</script>

<template>
    <div
        class="min-w-44 rounded-lg border-2 border-foreground bg-card px-3 py-2.5 text-xs text-foreground shadow-sm"
        data-testid="analytics-chart-tooltip"
    >
        <p v-if="x !== undefined" class="mb-2 font-medium">
            {{ labelFormatter ? labelFormatter(x) : x }}
        </p>
        <div class="grid gap-1.5">
            <div
                v-for="entry in entries"
                :key="entry.key"
                class="flex items-center justify-between gap-4"
            >
                <span
                    class="inline-flex min-w-0 items-center gap-1.5 text-muted-foreground"
                >
                    <img
                        v-if="entry.item.icon"
                        data-testid="analytics-tooltip-icon"
                        :src="entry.item.icon"
                        alt=""
                        class="size-4 shrink-0 object-contain"
                    />
                    <span class="truncate">{{ entry.item.label }}</span>
                </span>
                <span class="font-medium tabular-nums">{{
                    entry.value.toLocaleString(activeLocale)
                }}</span>
            </div>
        </div>
    </div>
</template>
