<script setup lang="ts">
import { IconArrowDown, IconArrowsSort } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
}>();
const sortBy = ref<'posts' | 'reactions' | 'comments' | 'engagement_rate'>(
    'posts',
);
const sorted = computed(() =>
    [...props.rows].sort(
        (a, b) =>
            (b[sortBy.value].value ?? -1) - (a[sortBy.value].value ?? -1) ||
            a.social_account_key.localeCompare(b.social_account_key),
    ),
);
const columns = ['posts', 'reactions', 'comments', 'engagement_rate'] as const;
const display = (
    row: PerformanceRow,
    key: (typeof columns)[number],
): string => {
    const value = row[key].value;
    return value === null
        ? '—'
        : key === 'engagement_rate'
          ? formatPercent(value)
          : formatNumberCompact(value);
};
const change = (row: PerformanceRow, key: (typeof columns)[number]): string => {
    const value = row[key].change;
    return value === null ? '—' : formatPercentChange(value);
};
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.performance')"
        :range="range"
    >
        <div
            v-if="rows.length === 0"
            class="rounded-lg border border-dashed border-border px-5 py-10 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_performance') }}
        </div>
        <Table v-else class="min-w-[720px]">
            <TableHeader>
                <TableRow>
                    <TableHead>
                        {{ $t('analytics.dashboard.channel') }}
                    </TableHead>
                    <TableHead
                        v-for="column in columns"
                        :key="column"
                        class="text-right"
                        :aria-sort="sortBy === column ? 'descending' : 'none'"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            @click="sortBy = column"
                        >
                            {{ $t(`analytics.dashboard.${column}`) }}
                            <IconArrowDown
                                v-if="sortBy === column"
                                class="size-3.5 text-primary"
                                aria-hidden="true"
                            />
                            <IconArrowsSort
                                v-else
                                class="size-3.5"
                                aria-hidden="true"
                            />
                        </button>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="row in sorted" :key="row.social_account_key">
                    <th scope="row" class="px-3 py-3 text-left font-normal">
                        <AccountIdentity :account="row" />
                    </th>
                    <TableCell
                        v-for="column in columns"
                        :key="column"
                        class="text-right tabular-nums"
                    >
                        <span class="font-medium">{{
                            display(row, column)
                        }}</span>
                        <span
                            v-if="row[column].change !== null"
                            class="ml-2 inline-flex rounded-md px-1.5 py-0.5 text-xs font-medium"
                            :class="
                                row[column].change! >= 0
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : 'bg-rose-50 text-rose-700'
                            "
                            >{{ row[column].change! >= 0 ? '↗' : '↘' }}
                            {{ change(row, column) }}</span
                        >
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </AnalyticsSection>
</template>
