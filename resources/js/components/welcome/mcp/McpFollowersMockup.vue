<script setup lang="ts">
import PlatformLogo from '@/components/PlatformLogo.vue';
import { accountColor } from '@/lib/analyticsColors';

/**
 * Illustrative follower history, one line per network, drawn on a 0–100
 * viewBox so the example reads like the Insights followers chart.
 */
const accounts = [
    { platform: 'linkedin', growth: '+640', points: [62, 55, 48, 40, 30, 18] },
    { platform: 'instagram', growth: '+420', points: [70, 66, 60, 55, 49, 42] },
    { platform: 'x', growth: '+140', points: [84, 82, 80, 77, 75, 72] },
].map((account, index) => ({ ...account, color: accountColor(index) }));

const toPolyline = (points: number[]): string =>
    points.map((y, index) => `${(index * 100) / (points.length - 1)},${y}`).join(' ');
</script>

<template>
    <article class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4">
        <div class="flex items-baseline gap-2">
            <span
                class="font-heading text-[28px] leading-[35px] font-medium tabular-nums"
            >
                12.4k
            </span>
            <span class="text-sm text-muted-foreground">
                {{ $t('analytics.dashboard.total_followers') }}
            </span>
        </div>
        <svg
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            class="h-24 w-full border-t border-border pt-2"
        >
            <polyline
                v-for="account in accounts"
                :key="account.platform"
                :points="toPolyline(account.points)"
                fill="none"
                :stroke="account.color"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
        </svg>
        <ul class="flex flex-wrap gap-x-5 gap-y-2 border-t border-border pt-3">
            <li
                v-for="account in accounts"
                :key="account.platform"
                class="flex items-center gap-1.5 text-sm"
            >
                <span
                    class="size-2.5 shrink-0 rounded-[3px]"
                    :style="{ backgroundColor: account.color }"
                />
                <PlatformLogo :platform="account.platform" :size="16" />
                <span class="font-semibold tabular-nums">
                    {{ account.growth }}
                </span>
            </li>
        </ul>
    </article>
</template>
