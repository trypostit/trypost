<script setup lang="ts">
import {
    IconChevronDown,
    IconChevronUp,
    IconTable,
    IconTrendingDown,
    IconTrendingUp,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    PERFORMANCE_METRICS,
    type PerformanceMetric,
    usePerformanceColumns,
} from '@/composables/usePerformanceColumns';
import date from '@/date';
import {
    formatNumberCompact,
    formatPercent,
    formatPercentChange,
} from '@/lib/utils';
import type {
    PerformanceRow,
    WorkspaceAnalyticsReport,
} from '@/types/analytics';

import AccountIdentity from './AccountIdentity.vue';
import AnalyticsSection from './AnalyticsSection.vue';

const props = defineProps<{
    rows: WorkspaceAnalyticsReport['performance'];
    range: WorkspaceAnalyticsReport['range'];
    previousRange: WorkspaceAnalyticsReport['previous_range'];
    filtered?: boolean;
}>();
const span = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;
const { columns, toggleColumn } = usePerformanceColumns();
type SortKey = PerformanceMetric | 'channel';
type SortDirection = 'asc' | 'desc';

const sortBy = ref<SortKey>('posts');
const sortDirection = ref<SortDirection>('desc');
const sortKey = computed<SortKey>(() =>
    sortBy.value === 'channel' || columns.value.includes(sortBy.value)
        ? sortBy.value
        : columns.value[0],
);
const channelName = (row: PerformanceRow): string =>
    row.username ?? row.name ?? row.platform;
const compareRows = (a: PerformanceRow, b: PerformanceRow): number => {
    const key = sortKey.value;

    if (key === 'channel') {
        return channelName(a).localeCompare(channelName(b));
    }

    const left = a[key].value;
    const right = b[key].value;

    if (left === null || right === null) {
        return left === right ? 0 : left === null ? 1 : -1;
    }

    return sortDirection.value === 'asc' ? left - right : right - left;
};
const sorted = computed(() =>
    [...props.rows].sort((a, b) => {
        const byKey =
            sortKey.value === 'channel' && sortDirection.value === 'desc'
                ? -compareRows(a, b)
                : compareRows(a, b);

        return byKey || a.social_account_key.localeCompare(b.social_account_key);
    }),
);
const sortOn = (key: SortKey): void => {
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';

        return;
    }

    sortBy.value = key;
    sortDirection.value = key === 'channel' ? 'asc' : 'desc';
};
const ariaSort = (key: SortKey): 'ascending' | 'descending' | 'none' =>
    sortKey.value === key
        ? sortDirection.value === 'asc'
            ? 'ascending'
            : 'descending'
        : 'none';
const display = (row: PerformanceRow, key: PerformanceMetric): string => {
    const value = row[key].value;
    return value === null
        ? '—'
        : key === 'engagement_rate'
          ? formatPercent(value)
          : formatNumberCompact(value);
};
const change = (row: PerformanceRow, key: PerformanceMetric): string => {
    const value = row[key].change;
    return value === null ? '—' : formatPercentChange(value);
};
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.performance')"
        :info="$t('analytics.insights.about.performance')"
        info-testid="analytics-performance-about"
        :subtitle="
            $t('analytics.ranges.compared_to', {
                current: span(range),
                previous: span(previousRange),
            })
        "
        subtitle-testid="analytics-performance-caption"
    >
        <template #actions>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="inline-flex size-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[state=open]:bg-accent data-[state=open]:text-foreground"
                    :aria-label="$t('analytics.insights.columns')"
                    :title="$t('analytics.insights.columns')"
                    data-testid="analytics-performance-columns"
                >
                    <IconTable
                        class="size-4"
                        aria-hidden="true"
                    />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                class="max-h-[min(28rem,var(--reka-dropdown-menu-content-available-height))] w-64 overflow-y-auto"
                data-testid="analytics-performance-columns-menu"
            >
                <DropdownMenuLabel>
                    {{ $t('analytics.insights.columns') }}
                </DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="metric in PERFORMANCE_METRICS"
                    :key="metric"
                    class="gap-2.5"
                    :disabled="
                        columns.length === 1 &&
                        columns.includes(metric)
                    "
                    :data-testid="`analytics-performance-columns-${metric}`"
                    @select.prevent="toggleColumn(metric)"
                >
                    <Checkbox
                        :model-value="columns.includes(metric)"
                        class="pointer-events-none"
                        tabindex="-1"
                        aria-hidden="true"
                    />
                    {{
                        $t(
                            `analytics.channel.metrics.${metric}.label`,
                        )
                    }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        </template>
        <div
            v-if="rows.length === 0"
            data-testid="analytics-performance-empty"
            class="rounded-lg border border-dashed border-border-strong bg-card px-5 py-10 text-center text-sm text-muted-foreground"
        >
            {{
                $t(
                    filtered
                        ? 'analytics.dashboard.filtered_no_posts'
                        : 'analytics.dashboard.no_performance',
                )
            }}
        </div>
        <div
            v-else
            class="min-w-0 rounded-lg border border-border bg-card"
        >
            <div class="min-w-0 overflow-x-auto rounded-lg">
                <Table class="min-w-[720px]">
                    <TableHeader>
                        <TableRow class="border-border-strong hover:bg-transparent">
                            <TableHead
                                class="h-12 border-r-0 px-4 py-3 leading-[17.5px]"
                                :aria-sort="ariaSort('channel')"
                                data-testid="analytics-performance-channel-header"
                            >
                                <button
                                    type="button"
                                    class="group inline-flex cursor-pointer items-center gap-1 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    data-testid="analytics-performance-sort-channel"
                                    @click="sortOn('channel')"
                                >
                                    {{ $t('analytics.dashboard.channel') }}
                                    <component
                                        :is="
                                            sortKey === 'channel' &&
                                            sortDirection === 'asc'
                                                ? IconChevronUp
                                                : IconChevronDown
                                        "
                                        :class="[
                                            'size-3 transition-opacity',
                                            sortKey === 'channel'
                                                ? 'opacity-100'
                                                : 'opacity-30 group-hover:opacity-60',
                                        ]"
                                        aria-hidden="true"
                                    />
                                </button>
                            </TableHead>
                            <TableHead
                                v-for="column in columns"
                                :key="column"
                                class="h-12 border-r-0 px-4 py-3 leading-[17.5px] whitespace-nowrap"
                                :aria-sort="ariaSort(column)"
                                :data-testid="`analytics-performance-column-${column}`"
                            >
                                <button
                                    type="button"
                                    class="group inline-flex cursor-pointer items-center gap-1 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    :data-testid="`analytics-performance-sort-${column}`"
                                    @click="sortOn(column)"
                                >
                                    {{
                                        $t(
                                            `analytics.channel.metrics.${column}.label`,
                                        )
                                    }}
                                    <component
                                        :is="
                                            sortKey === column &&
                                            sortDirection === 'asc'
                                                ? IconChevronUp
                                                : IconChevronDown
                                        "
                                        :class="[
                                            'size-3 transition-opacity',
                                            sortKey === column
                                                ? 'opacity-100'
                                                : 'opacity-30 group-hover:opacity-60',
                                        ]"
                                        aria-hidden="true"
                                    />
                                </button>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="row in sorted"
                            :key="row.social_account_key"
                            class="border-border-strong last:border-b-0"
                        >
                            <th
                                scope="row"
                                class="border-r-0 px-4 py-3 text-left font-normal"
                            >
                                <AccountIdentity :account="row" with-avatar />
                            </th>
                            <TableCell
                                v-for="column in columns"
                                :key="column"
                                class="border-r-0 px-4 py-3 whitespace-nowrap tabular-nums"
                            >
                                <span class="inline-flex items-center gap-2">
                                    <span class="text-sm text-foreground">{{
                                        display(row, column)
                                    }}</span>
                                    <span
                                        v-if="row[column].change !== null"
                                        class="inline-flex items-center gap-1 text-xs text-foreground"
                                    >
                                        <IconTrendingUp
                                            v-if="row[column].change! >= 0"
                                            class="size-4 shrink-0 text-success-text"
                                            aria-hidden="true"
                                        />
                                        <IconTrendingDown
                                            v-else
                                            class="size-4 shrink-0 text-destructive-text"
                                            aria-hidden="true"
                                        />
                                        {{ change(row, column) }}
                                    </span>
                                </span>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AnalyticsSection>
</template>
