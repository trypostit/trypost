<script setup lang="ts">
import { computed, ref } from 'vue';

import { formatNumberCompact } from '@/lib/utils';

import AccountIdentity from './AccountIdentity.vue';
import AnalyticsSection from './AnalyticsSection.vue';
import type { PerformanceRow, WorkspaceAnalyticsReport } from './types';

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
          ? `${value}%`
          : formatNumberCompact(value);
};
const change = (row: PerformanceRow, key: (typeof columns)[number]): string => {
    const value = row[key].change;
    return value === null
        ? '—'
        : `${value > 0 ? '+' : ''}${value}${key === 'engagement_rate' ? '%' : '%'}`;
};
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.performance')"
        :subtitle="`${range.start} – ${range.end}`"
    >
        <div
            v-if="rows.length === 0"
            class="rounded-lg border border-dashed border-border px-5 py-10 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_performance') }}
        </div>
        <div
            v-else
            class="overflow-x-auto rounded-lg border border-border bg-background"
        >
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead
                    class="border-b border-border bg-muted/25 text-xs text-muted-foreground"
                >
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">
                            {{ $t('analytics.dashboard.channel') }}
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column"
                            scope="col"
                            class="px-4 py-3 text-right font-medium"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                :aria-sort="
                                    sortBy === column ? 'descending' : undefined
                                "
                                @click="sortBy = column"
                            >
                                {{ $t(`analytics.dashboard.${column}`) }}
                                <span aria-hidden="true">↕</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in sorted"
                        :key="row.social_account_key"
                        class="border-b border-border last:border-0"
                    >
                        <th scope="row" class="px-4 py-3 font-normal">
                            <AccountIdentity :account="row" />
                        </th>
                        <td
                            v-for="column in columns"
                            :key="column"
                            class="px-4 py-3 text-right tabular-nums"
                        >
                            <span class="font-medium">{{
                                display(row, column)
                            }}</span>
                            <span
                                class="ml-2 text-xs"
                                :class="
                                    row[column].change === null
                                        ? 'text-muted-foreground'
                                        : row[column].change! >= 0
                                          ? 'text-emerald-700 dark:text-emerald-400'
                                          : 'text-rose-700 dark:text-rose-400'
                                "
                                >{{ change(row, column) }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AnalyticsSection>
</template>
