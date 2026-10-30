<script setup lang="ts">
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';

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
        class="min-w-52 overflow-hidden rounded-xl bg-popover text-xs text-popover-foreground shadow-md ring-1 ring-foreground/5 dark:ring-border"
        data-testid="analytics-chart-tooltip"
    >
        <p
            v-if="x !== undefined"
            class="border-b border-border px-3 py-2 font-medium text-foreground"
        >
            {{ labelFormatter ? labelFormatter(x) : x }}
        </p>
        <div class="grid gap-2 px-3 py-2.5">
            <div v-for="entry in entries" :key="entry.key" class="grid gap-0.5">
                <div class="flex items-center justify-between gap-6">
                    <span
                        class="inline-flex min-w-0 items-center gap-2 text-muted-foreground"
                    >
                        <span
                            data-testid="analytics-tooltip-swatch"
                            class="size-2.5 shrink-0 rounded-[3px]"
                            :style="{ backgroundColor: entry.item.color }"
                        />
                        <ChannelAvatar
                            v-if="entry.item.avatar"
                            :platform="entry.item.avatar.platform"
                            :name="entry.item.avatar.name"
                            :src="entry.item.avatar.src"
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
                <p
                    v-if="entry.detail"
                    class="ps-[18px] text-muted-foreground"
                    data-testid="analytics-tooltip-detail"
                >
                    {{ entry.detail }}
                </p>
            </div>
        </div>
    </div>
</template>
