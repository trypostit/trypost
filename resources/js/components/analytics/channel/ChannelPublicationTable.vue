<script setup lang="ts">
import { InfiniteScroll, Link, router } from '@inertiajs/vue3';
import { IconChevronDown, IconPhoto } from '@tabler/icons-vue';
import { computed } from 'vue';

import AnalyticsModeToggle from '@/components/analytics/workspace/AnalyticsModeToggle.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import date from '@/date';
import { formatNumberCompact, formatPercent } from '@/lib/utils';
import type {
    AnalyticsReport,
    ChannelInsightsFilters,
    ChannelPublicationRow,
    PublicationPeriod,
    SummaryMetric,
} from '@/types/analytics';

const props = defineProps<{
    report: AnalyticsReport;
    filters: ChannelInsightsFilters;
    rows: ChannelPublicationRow[];
    availableMetrics: SummaryMetric[];
    sortableMetrics: SummaryMetric[];
    url: string;
}>();

const columns = computed<SummaryMetric[]>(() =>
    props.availableMetrics.filter((metric) =>
        props.sortableMetrics.includes(metric),
    ),
);
const periods = [
    {
        mode: 'current',
        label: 'analytics.channel.this_period',
        test: 'insights-period-current',
    },
    {
        mode: 'previous',
        label: 'analytics.channel.previous_period',
        test: 'insights-period-previous',
    },
] as const;
const periodRange = computed(() =>
    props.filters.period === 'previous'
        ? props.report.previous_range
        : props.report.range,
);
const postCount = computed(
    () =>
        (props.filters.period === 'previous'
            ? props.report.summary.posts.previous
            : props.report.summary.posts.value) ?? 0,
);

const reload = (
    changes: Partial<{ period: PublicationPeriod; sort: SummaryMetric }>,
): void => {
    const { range, start, end, period, sort } = props.filters;

    router.get(
        props.url,
        {
            range,
            ...(range === 'custom' ? { start, end } : {}),
            period,
            sort,
            ...changes,
        },
        {
            only: ['publications', 'filters'],
            reset: ['publications'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const changePeriod = (period: string): void => {
    if (period !== props.filters.period) {
        reload({ period: period as PublicationPeriod });
    }
};

const changeSort = (sort: SummaryMetric): void => {
    if (sort !== props.filters.sort) {
        reload({ sort });
    }
};

const networkUrl = (row: ChannelPublicationRow): string | null =>
    row.permalink && /^https:\/\//i.test(row.permalink) ? row.permalink : null;

const linkAttributes = (
    row: ChannelPublicationRow,
): Record<string, string> => {
    if (row.url) {
        return { href: row.url };
    }

    const permalink = networkUrl(row);

    return permalink
        ? { href: permalink, target: '_blank', rel: 'noopener noreferrer' }
        : {};
};

const display = (row: ChannelPublicationRow, key: SummaryMetric): string => {
    const value = row.metrics[key];

    if (value === null || value === undefined) {
        return '—';
    }

    return key === 'engagement_rate'
        ? formatPercent(value)
        : formatNumberCompact(value);
};
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.channel.performance')"
        :info="$t('analytics.insights.about.channel_posts')"
        info-testid="insights-posts-about"
        :range="periodRange"
    >
        <template #actions>
            <AnalyticsModeToggle
                :model-value="filters.period"
                label="analytics.channel.performance"
                :options="periods"
                @update:model-value="changePeriod"
            />
        </template>

        <div
            class="min-w-0 overflow-hidden rounded-lg border border-border bg-card"
        >
            <div
                v-if="rows.length === 0"
                class="px-5 py-10 text-center text-sm text-muted-foreground"
                data-testid="insights-posts-empty"
            >
                {{ $t('analytics.channel.no_posts') }}
            </div>
            <InfiniteScroll
                v-else
                data="publications"
                items-element="#insights-posts-body"
                preserve-url
            >
                <div class="overflow-x-auto">
                    <Table class="min-w-[720px]" data-testid="insights-posts">
                        <TableHeader>
                            <TableRow
                                class="border-border-strong hover:bg-transparent"
                            >
                                <TableHead
                                    class="h-12 border-r-0 px-4 py-3 leading-[17.5px]"
                                >
                                    {{
                                        $t('analytics.channel.posts_count', {
                                            count: formatNumberCompact(postCount),
                                        })
                                    }}
                                </TableHead>
                                <TableHead
                                    v-for="column in columns"
                                    :key="column"
                                    class="h-12 border-r-0 px-4 py-3 leading-[17.5px]"
                                    :aria-sort="
                                        filters.sort === column
                                            ? 'descending'
                                            : 'none'
                                    "
                                >
                                    <button
                                        type="button"
                                        class="group inline-flex cursor-pointer items-center gap-1 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        :data-testid="`insights-sort-${column}`"
                                        @click="changeSort(column)"
                                    >
                                        {{
                                            $t(
                                                `analytics.channel.metrics.${column}.label`,
                                            )
                                        }}
                                        <IconChevronDown
                                            :class="[
                                                'size-3 transition-opacity',
                                                filters.sort === column
                                                    ? 'opacity-100'
                                                    : 'opacity-30 group-hover:opacity-60',
                                            ]"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody id="insights-posts-body">
                            <TableRow
                                v-for="row in rows"
                                :key="row.id"
                                class="border-border-strong last:border-b-0"
                                :data-testid="`insights-posts-row-${row.id}`"
                            >
                                <TableCell class="border-r-0 px-4 py-3">
                                    <component
                                        :is="row.url ? Link : networkUrl(row) ? 'a' : 'span'"
                                        v-bind="linkAttributes(row)"
                                        class="flex max-w-md min-w-0 items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        :data-testid="`insights-posts-link-${row.id}`"
                                    >
                                        <span
                                            class="w-6 shrink-0 text-xs text-muted-foreground tabular-nums"
                                            >#{{ row.rank }}</span
                                        >
                                        <img
                                            v-if="row.thumbnail_url"
                                            :src="row.thumbnail_url"
                                            alt=""
                                            class="size-12 shrink-0 rounded-lg object-cover"
                                            loading="lazy"
                                        />
                                        <span
                                            v-else
                                            class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                                        >
                                            <IconPhoto
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <span
                                            class="flex min-w-0 flex-col whitespace-normal"
                                        >
                                            <span
                                                class="line-clamp-2 text-sm text-foreground"
                                            >
                                                {{
                                                    row.excerpt ||
                                                    $t(
                                                        'analytics.dashboard.no_excerpt',
                                                    )
                                                }}
                                            </span>
                                            <span
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{
                                                    date.formatDateShort(
                                                        row.published_at,
                                                    )
                                                }}
                                            </span>
                                        </span>
                                    </component>
                                </TableCell>
                                <TableCell
                                    v-for="column in columns"
                                    :key="column"
                                    class="border-r-0 px-4 py-3 tabular-nums"
                                >
                                    {{ display(row, column) }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </InfiniteScroll>
        </div>
    </AnalyticsSection>
</template>
