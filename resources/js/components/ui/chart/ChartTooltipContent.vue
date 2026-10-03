<script setup lang="ts">
import { computed } from 'vue';

import { activeLocale } from '@/language';

import type { ChartConfig } from '.';

const props = withDefaults(
    defineProps<{
        payload?: Record<string, unknown>;
        config?: ChartConfig;
        labelFormatter?: (value: number | Date) => string;
        detailFormatter?: (key: string, value: number) => string | null;
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
                      detail: props.detailFormatter?.(key, value) ?? null,
                  },
              ]
            : [];
    }),
);
</script>

<template>
    <div
        class="min-w-48 rounded-lg border border-border-strong bg-popover px-4 py-3 text-xs text-muted-foreground shadow-md"
        data-testid="analytics-chart-tooltip"
    >
        <p v-if="x !== undefined" class="mb-2">
            {{ labelFormatter ? labelFormatter(x) : x }}
        </p>
        <div class="grid gap-1">
            <div v-for="entry in entries" :key="entry.key" class="grid gap-1">
                <div class="flex items-center justify-between gap-4">
                    <span class="inline-flex min-w-0 items-center gap-1.5">
                        <span
                            data-testid="analytics-tooltip-swatch"
                            class="size-2.5 shrink-0 rounded-[3px]"
                            :style="{ backgroundColor: entry.item.color }"
                        />
                        <img
                            v-if="entry.item.icon"
                            data-testid="analytics-tooltip-icon"
                            :src="entry.item.icon"
                            alt=""
                            class="size-3 shrink-0 object-contain"
                        />
                        <span class="truncate">{{ entry.item.label }}</span>
                    </span>
                    <span
                        class="font-semibold text-foreground tabular-nums"
                        data-testid="analytics-tooltip-value"
                        >{{ entry.value.toLocaleString(activeLocale) }}</span
                    >
                </div>
                <p
                    v-if="entry.detail"
                    class="pl-4"
                    data-testid="analytics-tooltip-detail"
                >
                    {{ entry.detail }}
                </p>
            </div>
        </div>
    </div>
</template>
