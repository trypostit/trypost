<script setup lang="ts">
import {
    IconActivity,
    IconBolt,
    IconChartBar,
    IconChartLine,
    IconCheck,
    IconChevronDown,
    IconCopy,
    IconEye,
    IconPlayerPlay,
    IconSquareDashed,
    IconSquareFilled,
    IconTrendingDown,
    IconTrendingUp,
    IconUserPlus,
    IconUsers,
    IconWorld,
} from '@tabler/icons-vue';
import { wTrans } from 'laravel-vue-i18n';
import { computed, ref, type Component } from 'vue';

import ShareChartButton from '@/components/analytics/ShareChartButton.vue';
import AnalyticsModeToggle from '@/components/analytics/workspace/AnalyticsModeToggle.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import date from '@/date';
import {
    availablePresets,
    bucketLabel,
    formatSeriesValue,
    growthRateSeries,
    hasEnoughHistory,
    isGrowthRate,
    menuMetrics,
    metricInsight,
    monthLabel,
    POST_TOTAL_METRICS,
    seriesFor,
    type ChartMode,
    type PresetKey,
    type Selection,
    type SeriesSpec,
} from '@/lib/channelMetrics';
import {
    channelSeriesShareOptions,
    isShareableSeriesMetric,
    shareRange,
} from '@/lib/insightsShare';
import type { ShareMetricOption } from '@/lib/insightsShareCard';
import type { ChannelMetricSeries, SeriesMetric } from '@/types/analytics';

import ChannelMetricsChart from './ChannelMetricsChart.vue';

const props = defineProps<{
    series?: ChannelMetricSeries;
    channel: string;
}>();

const METRIC_ICONS: Record<SeriesMetric, Component> = {
    posts: IconCopy,
    followers: IconUsers,
    net_followers: IconUserPlus,
    reach: IconWorld,
    views: IconPlayerPlay,
    impressions: IconChartLine,
    profile_visits: IconEye,
    growth_rate: IconChartBar,
};
const PRESET_ICONS: Record<PresetKey, Component> = {
    content_impact: IconBolt,
    audience_growth: IconTrendingUp,
    visibility: IconEye,
    follower_growth_rate: IconChartBar,
};
const modes = [
    {
        mode: 'current',
        label: 'analytics.channel.this_period',
        test: 'insights-metrics-mode-current',
        icon: IconSquareFilled,
    },
    {
        mode: 'previous',
        label: 'analytics.channel.metrics_card.comparison',
        test: 'insights-metrics-mode-previous',
        icon: IconSquareDashed,
    },
    {
        mode: 'both',
        label: 'analytics.channel.metrics_card.both',
        test: 'insights-metrics-mode-both',
    },
] as const;
const SELECTED = 'border-transparent bg-primary-selected text-primary-text hover:bg-primary-selected hover:text-primary-text';

const translatedLabels = Object.fromEntries(
    (
        [
            'posts',
            'followers',
            'net_followers',
            'reach',
            'views',
            'impressions',
            'profile_visits',
            'growth_rate',
        ] as SeriesMetric[]
    ).map((metric) => [metric, wTrans(`analytics.channel.series.${metric}`)]),
);
const comparisonAxis = wTrans('analytics.channel.metrics_card.comparison_axis');
const seriesLabels = computed<Partial<Record<SeriesMetric, string>>>(() =>
    Object.fromEntries(
        Object.entries(translatedLabels).map(([metric, label]) => [
            metric,
            label.value,
        ]),
    ),
);

const primaryMetric = ref<SeriesMetric>('posts');
const selection = ref<Selection>({ kind: 'preset', preset: 'content_impact' });
const mode = ref<ChartMode>('current');

const menu = computed<SeriesMetric[]>(() =>
    menuMetrics(props.series?.metrics ?? []),
);
const presets = computed(() =>
    props.series ? availablePresets(props.series) : [],
);
const activeSelection = computed<Selection>(() => {
    const current = selection.value;

    if (
        current.kind === 'preset' &&
        !presets.value.some((preset) => preset.key === current.preset)
    ) {
        return {
            kind: 'metric',
            metric: menu.value.includes(primaryMetric.value)
                ? primaryMetric.value
                : 'posts',
        };
    }

    if (current.kind === 'metric' && !menu.value.includes(current.metric)) {
        return { kind: 'metric', metric: 'posts' };
    }

    return current;
});
const shownPrimary = computed<SeriesMetric>(() =>
    menu.value.includes(primaryMetric.value) ? primaryMetric.value : 'posts',
);
const growthRate = computed(() => isGrowthRate(activeSelection.value));
const chartSeries = computed<ChannelMetricSeries | undefined>(() =>
    growthRate.value && props.series?.growth
        ? growthRateSeries(props.series.growth)
        : props.series,
);
const chartMode = computed<ChartMode>(() =>
    growthRate.value ? 'current' : mode.value,
);
const specs = computed<SeriesSpec[]>(() => seriesFor(activeSelection.value));
const enoughHistory = computed(() =>
    chartSeries.value
        ? hasEnoughHistory(chartSeries.value, specs.value, chartMode.value)
        : false,
);
const insight = computed(() =>
    props.series
        ? metricInsight(props.series, activeSelection.value, chartMode.value)
        : null,
);
const insightIcon = computed(() => {
    if (
        insight.value?.subject === 'audience_compared' ||
        insight.value?.direction === 'flat'
    ) {
        return IconActivity;
    }

    return insight.value?.direction === 'down'
        ? IconTrendingDown
        : IconTrendingUp;
});
const showsPostTotals = computed(() =>
    specs.value.some((spec) => POST_TOTAL_METRICS.includes(spec.metric)),
);
const rangeCaption = computed(() => {
    if (!props.series) {
        return null;
    }

    if (growthRate.value && props.series.growth) {
        const { start, end } = props.series.growth.range;

        return `${monthLabel(start)} – ${monthLabel(end)}`;
    }

    return `${date.formatDayMonthYear(props.series.range.start)} – ${date.formatDayMonthYear(props.series.range.end)}`;
});
const previousCaption = computed(() =>
    props.series
        ? `${date.formatDayMonthYear(props.series.previous_range.start)} – ${date.formatDayMonthYear(props.series.previous_range.end)}`
        : '',
);
/** What the Share button needs, or null while the series has nothing to share. */
const share = computed(
    (): { range: string; build: () => ShareMetricOption[] } | null => {
        const series = props.series;

        if (!series?.metrics.some(isShareableSeriesMetric)) {
            return null;
        }

        const range = shareRange(series.range);

        return {
            range,
            build: () => channelSeriesShareOptions(series, props.channel, range),
        };
    },
);
const shownTotals = computed(() =>
    chartMode.value === 'previous'
        ? chartSeries.value?.totals.previous
        : chartSeries.value?.totals.current,
);
const axisLabels = computed<Partial<Record<SeriesSpec['axis'], string>>>(() =>
    Object.fromEntries(
        specs.value.map((spec) => {
            const label = seriesLabels.value[spec.metric] ?? '';

            return [
                spec.axis,
                chartMode.value === 'previous'
                    ? comparisonAxis.value.replace(':metric', label)
                    : label,
            ];
        }),
    ),
);

const isMetricActive = computed(
    () => activeSelection.value.kind === 'metric',
);
const isPresetActive = (preset: PresetKey): boolean =>
    activeSelection.value.kind === 'preset' &&
    activeSelection.value.preset === preset;

const previousSwatch = (spec: SeriesSpec): Record<string, string> =>
    spec.type === 'bar'
        ? {
              backgroundImage: `repeating-linear-gradient(135deg, ${spec.color} 0 1.5px, transparent 1.5px 3.5px)`,
          }
        : { border: `1.5px dashed ${spec.color}` };

const showPrimaryMetric = (): void => {
    selection.value = { kind: 'metric', metric: shownPrimary.value };
};

const chooseMetric = (metric: SeriesMetric): void => {
    primaryMetric.value = metric;
    selection.value = { kind: 'metric', metric };
};

const choosePreset = (preset: PresetKey): void => {
    selection.value = { kind: 'preset', preset };
};

const changeMode = (value: string): void => {
    mode.value = value as ChartMode;
};
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.channel.metrics_card.title')"
        subtitle-testid="insights-metrics-caption"
    >
        <template v-if="share" #actions>
            <ShareChartButton
                variant="chart"
                :subject="channel"
                :range="share.range"
                :build="share.build"
                :initial-key="specs[0]?.metric"
                test-id="share-channel-metrics"
            />
        </template>
        <template v-if="rangeCaption" #subtitle>
            <template v-if="growthRate">
                {{ rangeCaption }} ·
                <span
                    class="text-warning"
                    data-testid="insights-metrics-filters-note"
                    >{{
                        $t('analytics.channel.metrics_card.growth.filters_note')
                    }}</span
                >
            </template>
            <template v-else>
                {{
                    $t('analytics.ranges.compared_to', {
                        current: rangeCaption,
                        previous: previousCaption,
                    })
                }}
            </template>
        </template>

        <div data-testid="insights-metrics">
            <div
                v-if="!series"
                class="grid gap-2"
                data-testid="insights-metrics-skeleton"
                aria-busy="true"
            >
                <div class="flex flex-wrap gap-2 pb-2">
                    <Skeleton class="h-7 w-24 rounded-md" />
                    <Skeleton class="h-7 w-32 rounded-md" />
                    <Skeleton class="h-7 w-32 rounded-md" />
                    <Skeleton class="h-7 w-24 rounded-md" />
                </div>
                <div>
                    <div class="mb-6 flex gap-6">
                        <Skeleton class="h-12 w-20" />
                        <Skeleton class="h-12 w-20" />
                    </div>
                    <Skeleton class="h-64 w-full sm:h-72" />
                </div>
            </div>

            <template v-else>
                <div
                    class="flex flex-wrap items-center gap-2 pb-5"
                    data-testid="insights-metrics-controls"
                >
                    <div
                        class="inline-flex items-center"
                        data-testid="insights-metric-split"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            class="rounded-e-none bg-card"
                            :class="isMetricActive && SELECTED"
                            :aria-pressed="isMetricActive"
                            data-testid="insights-metric-primary"
                            @click="showPrimaryMetric"
                        >
                            <component
                                :is="METRIC_ICONS[shownPrimary]"
                                aria-hidden="true"
                            />
                            {{
                                $t(
                                    `analytics.channel.metrics_card.menu.${shownPrimary}`,
                                )
                            }}
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="rounded-s-none border-s-0 bg-card px-1"
                                    :class="isMetricActive && SELECTED"
                                    :aria-label="
                                        $t(
                                            'analytics.channel.metrics_card.metric_menu',
                                            {
                                                metric: $t(
                                                    `analytics.channel.metrics_card.menu.${shownPrimary}`,
                                                ),
                                            },
                                        )
                                    "
                                    data-testid="insights-metric-menu"
                                >
                                    <IconChevronDown aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="start"
                                class="w-56"
                                data-testid="insights-metric-options"
                            >
                                <DropdownMenuItem
                                    v-for="metric in menu"
                                    :key="metric"
                                    :data-testid="`insights-metric-option-${metric}`"
                                    @select="chooseMetric(metric)"
                                >
                                    <span class="flex size-4 shrink-0">
                                        <IconCheck
                                            v-if="shownPrimary === metric"
                                            :data-testid="`insights-metric-option-${metric}-check`"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <component
                                        :is="METRIC_ICONS[metric]"
                                        aria-hidden="true"
                                    />
                                    <span class="truncate">{{
                                        $t(
                                            `analytics.channel.metrics_card.menu.${metric}`,
                                        )
                                    }}</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>

                    <TooltipProvider :delay-duration="300">
                        <Tooltip v-for="preset in presets" :key="preset.key">
                            <TooltipTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="bg-card"
                                    :class="
                                        isPresetActive(preset.key) && SELECTED
                                    "
                                    :aria-pressed="isPresetActive(preset.key)"
                                    :data-testid="`insights-preset-${preset.key}`"
                                    @click="choosePreset(preset.key)"
                                >
                                    <component
                                        :is="PRESET_ICONS[preset.key]"
                                        aria-hidden="true"
                                    />
                                    {{
                                        $t(
                                            `analytics.channel.metrics_card.presets.${preset.key}.label`,
                                        )
                                    }}
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent
                                class="grid max-w-64 gap-1"
                                :data-testid="`insights-preset-${preset.key}-about`"
                            >
                                <span class="font-medium">{{
                                    $t(
                                        `analytics.channel.metrics_card.presets.${preset.key}.title`,
                                    )
                                }}</span>
                                <span class="opacity-80">{{
                                    $t(
                                        `analytics.channel.metrics_card.presets.${preset.key}.about`,
                                    )
                                }}</span>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>

                <div class="min-w-0">
                    <p
                        v-if="insight && enoughHistory"
                        class="mb-5 flex items-center gap-2 rounded-lg bg-info-subtle px-4 py-3 text-sm text-info-text"
                        data-testid="insights-metrics-insight"
                        :data-insight="`${insight.subject}.${insight.direction}`"
                    >
                        <component
                            :is="insightIcon"
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{
                            $t(
                                `analytics.channel.metrics_card.insight.${insight.subject}.${insight.direction}`,
                            )
                        }}
                    </p>

                    <div>
                        <div
                            class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div
                                class="flex flex-wrap gap-x-8 gap-y-3"
                                data-testid="insights-metrics-legend"
                            >
                                <div
                                    v-for="spec in specs"
                                    :key="spec.metric"
                                    class="flex min-w-0 flex-col gap-1"
                                    :data-testid="`insights-metrics-legend-${spec.metric}`"
                                >
                                    <span
                                        class="text-sm whitespace-nowrap text-muted-foreground"
                                        >{{
                                            $t(
                                                `analytics.channel.series.${spec.metric}`,
                                            )
                                        }}</span
                                    >
                                    <span class="flex items-center gap-3">
                                        <span class="flex items-center gap-1.5">
                                            <span
                                                class="size-2.5 shrink-0 rounded-[3px]"
                                                :style="
                                                    chartMode === 'previous'
                                                        ? previousSwatch(spec)
                                                        : {
                                                              backgroundColor:
                                                                  spec.color,
                                                          }
                                                "
                                            />
                                            <span
                                                class="font-heading text-2xl leading-8 font-medium text-foreground tabular-nums"
                                                :data-testid="`insights-metrics-legend-${spec.metric}-value`"
                                                >{{
                                                    formatSeriesValue(
                                                        shownTotals?.[
                                                            spec.metric
                                                        ],
                                                        spec.metric,
                                                    )
                                                }}</span
                                            >
                                        </span>
                                        <span
                                            v-if="chartMode === 'both'"
                                            class="flex items-center gap-1.5"
                                        >
                                            <span
                                                class="size-2.5 shrink-0 rounded-[3px]"
                                                :style="previousSwatch(spec)"
                                            />
                                            <span
                                                class="font-heading text-2xl leading-8 font-medium text-muted-foreground tabular-nums"
                                                :data-testid="`insights-metrics-legend-${spec.metric}-previous`"
                                                >{{
                                                    formatSeriesValue(
                                                        series.totals.previous[
                                                            spec.metric
                                                        ],
                                                        spec.metric,
                                                    )
                                                }}</span
                                            >
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <AnalyticsModeToggle
                                v-if="!growthRate"
                                :model-value="mode"
                                label="analytics.channel.metrics_card.chart_mode"
                                :options="modes"
                                class="self-start"
                                data-testid="insights-metrics-modes"
                                @update:model-value="changeMode"
                            />
                        </div>

                        <ChannelMetricsChart
                            v-if="enoughHistory && chartSeries"
                            :series="chartSeries"
                            :specs="specs"
                            :mode="chartMode"
                            :labels="seriesLabels"
                            :axis-labels="axisLabels"
                        />
                        <div
                            v-else
                            class="flex h-64 items-center justify-center rounded-lg border border-dashed border-border-strong px-5 text-center text-sm text-muted-foreground sm:h-72"
                            data-testid="insights-metrics-no-history"
                        >
                            {{
                                $t(
                                    'analytics.channel.metrics_card.not_enough_history',
                                )
                            }}
                        </div>

                        <p
                            v-if="showsPostTotals"
                            class="mt-3 text-xs text-muted-foreground"
                            data-testid="insights-metrics-post-totals-note"
                        >
                            {{
                                $t(
                                    'analytics.channel.metrics_card.post_totals_note',
                                )
                            }}
                        </p>

                        <table
                            v-if="chartSeries"
                            class="sr-only"
                            data-testid="insights-metrics-table"
                        >
                            <caption>
                                {{
                                    $t('analytics.channel.metrics_card.title')
                                }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">
                                        {{
                                            $t(
                                                'analytics.channel.metrics_card.date',
                                            )
                                        }}
                                    </th>
                                    <template
                                        v-for="spec in specs"
                                        :key="spec.metric"
                                    >
                                        <th scope="col">
                                            {{
                                                $t(
                                                    `analytics.channel.series.${spec.metric}`,
                                                )
                                            }}
                                        </th>
                                        <th v-if="!growthRate" scope="col">
                                            {{
                                                $t(
                                                    'analytics.channel.metrics_card.previous_value',
                                                    {
                                                        metric: $t(
                                                            `analytics.channel.series.${spec.metric}`,
                                                        ),
                                                    },
                                                )
                                            }}
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(point, index) in chartSeries.current"
                                    :key="point.start"
                                >
                                    <th scope="row">
                                        {{
                                            bucketLabel(
                                                point,
                                                chartSeries.resolution,
                                            )
                                        }}
                                    </th>
                                    <template
                                        v-for="spec in specs"
                                        :key="spec.metric"
                                    >
                                        <td>
                                            {{
                                                formatSeriesValue(
                                                    point.values[spec.metric],
                                                    spec.metric,
                                                )
                                            }}
                                        </td>
                                        <td v-if="!growthRate">
                                            {{
                                                formatSeriesValue(
                                                    chartSeries.previous[index]
                                                        ?.values[spec.metric],
                                                    spec.metric,
                                                )
                                            }}
                                        </td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </AnalyticsSection>
</template>
