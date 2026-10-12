<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import type { ChartConfig } from '@/components/ui/chart';
import ChartTooltipContent from '@/components/ui/chart/ChartTooltipContent.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import dayjs from '@/dayjs';
import { accountColor } from '@/lib/analyticsColors';
import type { SharedData } from '@/types';

const WIDTH = 300;
const HEIGHT = 100;

/**
 * Illustrative follower history over the last six months, one curve per
 * network, so the example reads like the Insights followers chart. The last
 * month adds up to the 12.4k total and each network gains what the legend says.
 */
const accounts = [
    {
        platform: 'linkedin',
        start: 5200,
        growth: 640,
        values: [6, 24, 16, 48, 70, 100],
    },
    {
        platform: 'instagram',
        start: 4800,
        growth: 420,
        values: [22, 30, 52, 36, 58, 76],
    },
    {
        platform: 'x',
        start: 1200,
        growth: 140,
        values: [4, 12, 8, 18, 14, 30],
    },
].map((account, index) => ({ ...account, color: accountColor(index) }));

const months = Array.from({ length: 6 }, (_, index) =>
    dayjs().subtract(5 - index, 'month'),
);

const hovered = ref<number | null>(null);

const owner = usePage<SharedData>().props.auth.user;

const tooltipConfig: ChartConfig = Object.fromEntries(
    accounts.map((account) => [
        account.platform,
        {
            label: getPlatformLabel(account.platform),
            color: account.color,
            avatar: {
                platform: account.platform,
                name: owner.name,
                src: owner.has_photo ? owner.photo_url : null,
            },
        },
    ]),
);

const pointY = (value: number): number => HEIGHT - 5 - value * 0.9;

const pointX = (index: number): number => (index * WIDTH) / (months.length - 1);

const followersAt = (
    account: (typeof accounts)[number],
    index: number,
): number => {
    const first = account.values[0];
    const last = account.values[account.values.length - 1];

    return Math.round(
        account.start +
            ((account.values[index] - first) / (last - first)) * account.growth,
    );
};

/** A smooth Catmull-Rom curve through the points, as cubic Béziers. */
const toCurve = (values: number[]): string => {
    const points = values.map(
        (value, index): [number, number] => [pointX(index), pointY(value)],
    );

    return points.reduce((path, [x, y], index) => {
        if (index === 0) {
            return `M${x},${y}`;
        }

        const [x0, y0] = points[Math.max(index - 2, 0)];
        const [x1, y1] = points[index - 1];
        const [x3, y3] = points[Math.min(index + 1, points.length - 1)];

        return `${path} C${x1 + (x - x0) / 6},${y1 + (y - y0) / 6} ${x - (x3 - x1) / 6},${y - (y3 - y1) / 6} ${x},${y}`;
    }, '');
};

const toArea = (values: number[]): string =>
    `${toCurve(values)} L${WIDTH},${HEIGHT} L0,${HEIGHT} Z`;

/** Beside the guide line, on the side with room, so it never hides the points. */
const tooltipPosition = (index: number): Record<string, string> => ({
    left: `${(index / (months.length - 1)) * 100}%`,
    transform:
        index < months.length / 2
            ? 'translateX(12px)'
            : 'translateX(calc(-100% - 12px))',
});

const tooltipPayload = (index: number): Record<string, number> =>
    Object.fromEntries(
        accounts.map((account) => [
            account.platform,
            followersAt(account, index),
        ]),
    );

const formatMonth = (value: number | Date): string =>
    dayjs(value).format('MMMM YYYY');

const showMonth = (index: number): void => {
    hovered.value = index;
};

const hideMonth = (): void => {
    hovered.value = null;
};
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4"
    >
        <div class="flex flex-col gap-0.5">
            <span
                class="font-heading text-xl leading-tight font-medium tabular-nums"
            >
                12.4k
            </span>
            <span class="text-xs text-muted-foreground">
                {{ $t('analytics.dashboard.total_followers') }}
            </span>
        </div>
        <div class="flex flex-col gap-1.5 border-t border-border pt-3">
            <div class="relative" dir="ltr" @mouseleave="hideMonth">
                <svg
                    :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
                    preserveAspectRatio="none"
                    class="h-28 w-full overflow-visible"
                >
                    <path
                        :d="toArea(accounts[0].values)"
                        :fill="accounts[0].color"
                        fill-opacity="0.08"
                    />
                    <line
                        v-if="hovered !== null"
                        :x1="pointX(hovered)"
                        :x2="pointX(hovered)"
                        y1="0"
                        :y2="HEIGHT"
                        class="stroke-border-strong"
                        vector-effect="non-scaling-stroke"
                    />
                    <path
                        v-for="account in accounts"
                        :key="account.platform"
                        :d="toCurve(account.values)"
                        fill="none"
                        :stroke="account.color"
                        stroke-width="1.25"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                    />
                </svg>

                <template v-if="hovered !== null">
                    <span
                        v-for="account in accounts"
                        :key="account.platform"
                        class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-card"
                        :style="{
                            left: `${(hovered / (months.length - 1)) * 100}%`,
                            top: `${(pointY(account.values[hovered]) / HEIGHT) * 100}%`,
                            backgroundColor: account.color,
                        }"
                    />
                    <div
                        class="pointer-events-none absolute -top-2 z-10"
                        :style="tooltipPosition(hovered)"
                        data-testid="welcome-mcp-followers-tooltip"
                    >
                        <ChartTooltipContent
                            :config="tooltipConfig"
                            :payload="tooltipPayload(hovered)"
                            :x="months[hovered].toDate()"
                            :label-formatter="formatMonth"
                        />
                    </div>
                </template>

                <div class="absolute inset-0 flex">
                    <span
                        v-for="(month, index) in months"
                        :key="month.format('YYYY-MM')"
                        class="h-full flex-1 cursor-crosshair"
                        :data-testid="`welcome-mcp-followers-month-${index}`"
                        @mouseenter="showMonth(index)"
                    />
                </div>
            </div>
            <div
                class="flex justify-between text-[11px] text-muted-foreground"
                dir="ltr"
            >
                <span
                    v-for="month in months"
                    :key="month.format('YYYY-MM')"
                >
                    {{ month.format('MMM') }}
                </span>
            </div>
        </div>
        <ul
            class="flex flex-wrap gap-x-5 gap-y-2 border-t border-border pt-3"
        >
            <li
                v-for="account in accounts"
                :key="account.platform"
                class="flex items-center gap-1.5 text-xs"
            >
                <span
                    class="size-2.5 shrink-0 rounded-[3px]"
                    :style="{ backgroundColor: account.color }"
                />
                <PlatformLogo :platform="account.platform" :size="16" />
                <span class="text-muted-foreground tabular-nums">
                    +{{ account.growth }}
                </span>
            </li>
        </ul>
    </article>
</template>
