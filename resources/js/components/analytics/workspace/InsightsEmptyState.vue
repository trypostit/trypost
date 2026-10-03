<script setup lang="ts">
import {
    IconInfoCircle,
    IconPlus,
    IconTrendingDown,
    IconTrendingUp,
} from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';

import AnalyticsSection from './AnalyticsSection.vue';

defineProps<{
    hasChannels: boolean;
}>();

const { canManageAccounts } = useWorkspaceAbilities();
const { open: openConnectDialog } = useConnectChannelDialog();

const tiles = [
    { left: 6.97, top: 5.09, emoji: '🔍', tone: 'bg-cyan-100 dark:bg-cyan-400/15' },
    { left: 11.02, top: 50, emoji: '📊', tone: 'bg-blue-100 dark:bg-blue-400/15' },
    { left: 1, top: 67.96, emoji: null, tone: 'bg-orange-100 dark:bg-orange-400/15' },
    { left: 20.98, top: 85.03, emoji: null, tone: 'bg-blue-100 dark:bg-blue-400/15' },
    { left: 82.5, top: 26.05, emoji: '🎯', tone: 'bg-blue-100 dark:bg-blue-400/15' },
    { left: 89.54, top: 70.06, emoji: '📈', tone: 'bg-amber-100 dark:bg-amber-400/15' },
    { left: 90.54, top: 5.09, emoji: null, tone: 'bg-green-100 dark:bg-green-400/15' },
];

const summaryCards = [
    { label: 'analytics.metrics.likes', up: true },
    { label: 'analytics.metrics.comments', up: false },
    { label: 'analytics.metrics.impressions', up: true },
    { label: 'analytics.detail.labels.engagement_rate', up: false },
];

const yTicks = [100, 80, 60, 40, 20];

const xMonths = [1, 3, 5, 7, 9, 11];

const postsCurve =
    'M0,200 C135,200 135,45 270,45 C395,45 395,187.5 520,187.5 C640,187.5 640,55 760,55 C880,55 880,175 1000,175';

const reachCurve =
    'M0,162.5 C150,162.5 150,100 300,100 C430,100 430,155 560,155 C680,155 680,32.5 800,32.5 C900,32.5 900,155 1000,155';
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6">
        <section
            class="relative flex min-h-[334px] min-w-0 flex-col items-center justify-center overflow-hidden rounded-xl bg-primary-subtle/70 px-6 py-12 text-center dark:bg-primary-subtle/40"
            data-testid="analytics-empty-state"
        >
            <div
                class="pointer-events-none absolute inset-0 opacity-60 dark:opacity-30"
                style="
                    background-image:
                        linear-gradient(to right, var(--background) 1px, transparent 1px),
                        linear-gradient(to bottom, var(--background) 1px, transparent 1px);
                    background-size: 51px 51px;
                "
                aria-hidden="true"
            />
            <div class="absolute inset-0 hidden md:block" aria-hidden="true">
                <div
                    v-for="(tile, index) in tiles"
                    :key="index"
                    class="group absolute flex size-10 items-center justify-center rounded-[8px] text-2xl leading-none"
                    :class="tile.tone"
                    :style="{ left: `${tile.left}%`, top: `${tile.top}%` }"
                    data-testid="analytics-empty-tile"
                >
                    <span
                        :class="
                            tile.emoji
                                ? ''
                                : 'opacity-0 transition-opacity duration-200 group-hover:opacity-100'
                        "
                        >{{ tile.emoji ?? '📊' }}</span
                    >
                </div>
            </div>
            <div class="relative flex max-w-3xl flex-col items-center">
                <h2
                    class="font-heading text-xl leading-[25px] font-normal text-foreground"
                    data-testid="analytics-empty-title"
                >
                    <template v-if="hasChannels">{{
                        $t('analytics.dashboard.no_data_title')
                    }}</template>
                    <template v-else
                        >{{ $t('analytics.dashboard.empty_title') }} 🔥</template
                    >
                </h2>
                <p class="mt-3 text-base text-foreground">
                    {{
                        hasChannels
                            ? $t('analytics.dashboard.no_data_body')
                            : $t('analytics.dashboard.empty_body')
                    }}
                </p>
                <Button
                    v-if="!hasChannels && canManageAccounts"
                    class="mt-4"
                    data-testid="analytics-empty-connect"
                    @click="openConnectDialog()"
                >
                    <IconPlus />
                    {{ $t('channels.connect') }}
                </Button>
            </div>
        </section>

        <div
            class="pointer-events-none flex min-w-0 flex-col gap-6 select-none"
            aria-hidden="true"
            data-testid="analytics-empty-preview"
        >
            <AnalyticsSection :title="$t('analytics.dashboard.summary')">
                <div
                    class="grid grid-cols-2 gap-2 lg:grid-cols-4"
                    data-testid="analytics-empty-summary"
                >
                    <div
                        v-for="card in summaryCards"
                        :key="card.label"
                        class="flex min-h-20 min-w-0 flex-col gap-3 rounded-lg border border-border bg-card px-4 py-3"
                    >
                        <div class="flex items-center justify-between gap-1">
                            <p class="truncate text-xs text-muted-foreground/80">
                                {{ $t(card.label) }}
                            </p>
                            <IconInfoCircle
                                class="size-3.5 shrink-0 text-muted-foreground/70"
                            />
                        </div>
                        <IconTrendingUp
                            v-if="card.up"
                            class="size-3.5 text-success/70"
                        />
                        <IconTrendingDown
                            v-else
                            class="size-3.5 text-destructive/60"
                        />
                    </div>
                </div>
            </AnalyticsSection>

            <AnalyticsSection :title="$t('analytics.dashboard.top_posts')">
                <div
                    class="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-5"
                    data-testid="analytics-empty-top-posts"
                >
                    <div
                        v-for="index in 5"
                        :key="index"
                        class="flex min-h-24 min-w-0 flex-col gap-2 rounded-lg border border-border bg-card px-4 py-3"
                    >
                        <div class="flex items-center justify-between">
                            <span class="h-1 w-6 rounded-full bg-secondary" />
                            <span class="h-1 w-10 rounded-full bg-secondary" />
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="flex flex-1 flex-col gap-1.5 pt-0.5">
                                <span class="h-1 w-1/5 rounded-full bg-secondary" />
                                <span class="h-1 w-full rounded-full bg-secondary" />
                                <span class="h-1 w-3/5 rounded-full bg-secondary" />
                            </div>
                            <span class="size-12 shrink-0 rounded-md bg-secondary" />
                        </div>
                    </div>
                </div>
            </AnalyticsSection>

            <AnalyticsSection :title="$t('analytics.dashboard.metrics')">
                <div
                    class="flex min-w-0 flex-col gap-4 rounded-lg border border-border bg-card px-4 pt-4 pb-3"
                    data-testid="analytics-empty-metrics"
                >
                    <div
                        class="flex items-center gap-4 text-xs text-muted-foreground/70"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <span class="h-0.5 w-2.5 rounded-full bg-border" />
                            {{ $t('analytics.dashboard.posts') }}
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="flex gap-0.5">
                                <span class="h-0.5 w-1 rounded-full bg-border" />
                                <span class="h-0.5 w-1 rounded-full bg-border" />
                            </span>
                            {{ $t('analytics.metrics.reach') }}
                        </span>
                    </div>
                    <div class="flex min-w-0 gap-2">
                        <div
                            class="relative h-48 w-6 shrink-0 text-[11px] text-muted-foreground/70"
                        >
                            <span
                                v-for="tick in yTicks"
                                :key="tick"
                                class="absolute left-0 -translate-y-1/2 tabular-nums"
                                :style="{ top: `${((100 - tick) / 80) * 100}%` }"
                                >{{ tick }}</span
                            >
                        </div>
                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                            <svg
                                class="h-48 w-full overflow-visible"
                                viewBox="0 0 1000 200"
                                preserveAspectRatio="none"
                                fill="none"
                            >
                                <line
                                    x1="0"
                                    y1="200"
                                    x2="1000"
                                    y2="200"
                                    class="stroke-border"
                                    vector-effect="non-scaling-stroke"
                                />
                                <path
                                    :d="postsCurve"
                                    class="stroke-muted-foreground/25"
                                    stroke-width="1.25"
                                    vector-effect="non-scaling-stroke"
                                />
                                <path
                                    :d="reachCurve"
                                    class="stroke-muted-foreground/25"
                                    stroke-width="1.25"
                                    stroke-dasharray="4 4"
                                    vector-effect="non-scaling-stroke"
                                />
                            </svg>
                            <div
                                class="flex justify-between text-[11px] text-muted-foreground/70"
                            >
                                <span v-for="month in xMonths" :key="month">{{
                                    date.formatShortMonth(month)
                                }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </AnalyticsSection>
        </div>
    </div>
</template>
