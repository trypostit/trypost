<script setup lang="ts">
import { computed } from 'vue';

import {
    accountColor,
    type FollowerAccount,
    type WorkspaceAnalyticsReport,
} from '../types';

const props = defineProps<{
    accounts: FollowerAccount[];
    series: WorkspaceAnalyticsReport['followers']['series'];
}>();

const plot = { left: 40, top: 18, width: 890, height: 176 };
const maximum = computed(() =>
    Math.max(
        1,
        ...props.series.flatMap((point) =>
            Object.values(point.accounts).filter(
                (value): value is number => value !== null,
            ),
        ),
    ),
);
const x = (index: number): number =>
    plot.left + (index / Math.max(1, props.series.length - 1)) * plot.width;
const y = (value: number): number =>
    plot.top + plot.height - (value / maximum.value) * plot.height;
const ticks = computed(() =>
    [0, 1, 2, 3, 4].map((step) => ({
        value: (maximum.value * step) / 4,
        y: y((maximum.value * step) / 4),
    })),
);
const paths = computed(() =>
    props.accounts.map((account, accountIndex) => {
        let segmentOpen = false;
        const segments: string[] = [];

        props.series.forEach((point, index) => {
            const value = point.accounts[account.social_account_key];
            if (value === null || value === undefined) {
                segmentOpen = false;
                return;
            }

            segments.push(`${segmentOpen ? 'L' : 'M'} ${x(index)} ${y(value)}`);
            segmentOpen = true;
        });

        return {
            key: account.social_account_key,
            path: segments.join(' '),
            color: accountColor(accountIndex),
        };
    }),
);

const points = computed(() =>
    props.accounts.flatMap((account, accountIndex) =>
        props.series.flatMap((point, index) => {
            const value = point.accounts[account.social_account_key];
            return value === null || value === undefined
                ? []
                : [
                      {
                          key: `${account.social_account_key}-${point.date}`,
                          x: x(index),
                          y: y(value),
                          value,
                          date: point.date,
                          color: accountColor(accountIndex),
                      },
                  ];
        }),
    ),
);

const labels = computed(() => {
    if (!props.series.length) return [];
    const last = props.series.length - 1;
    return [...new Set([0, Math.round(last / 2), last])].map(
        (index) => props.series[index]?.date ?? '',
    );
});
</script>

<template>
    <div
        class="min-w-[440px]"
        role="img"
        :aria-label="$t('analytics.dashboard.followers_line_description')"
    >
        <svg
            class="h-56 w-full overflow-visible"
            viewBox="0 0 960 220"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <g v-for="tick in ticks" :key="tick.y">
                <line
                    x1="40"
                    :y1="tick.y"
                    x2="930"
                    :y2="tick.y"
                    stroke="currentColor"
                    class="text-border"
                    stroke-width="1"
                />
                <text
                    x="32"
                    :y="tick.y + 4"
                    text-anchor="end"
                    class="fill-muted-foreground text-[11px]"
                >
                    {{ Math.round(tick.value).toLocaleString() }}
                </text>
            </g>
            <path
                v-for="line in paths"
                :key="line.key"
                :d="line.path"
                fill="none"
                :stroke="line.color"
                stroke-width="3"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
            <circle
                v-for="point in points"
                :key="point.key"
                :cx="point.x"
                :cy="point.y"
                r="3.5"
                :fill="point.color"
            >
                <title>
                    {{ point.date }}: {{ point.value.toLocaleString() }}
                </title>
            </circle>
        </svg>
        <div class="ml-10 flex justify-between text-xs text-muted-foreground">
            <span v-for="label in labels" :key="label">{{ label }}</span>
        </div>
    </div>
</template>
