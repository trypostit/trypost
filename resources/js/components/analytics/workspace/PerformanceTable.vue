<script setup lang="ts">
import { IconArrowDown, IconArrowsSort } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { formatNumberCompact, formatPercentChange } from '@/lib/utils';

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
        <div
            v-else
            class="overflow-x-auto rounded-xl border-2 border-foreground"
        >
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead
                    class="border-b-2 border-foreground bg-muted/40 text-xs text-foreground/70"
                >
                    <tr>
                        <th scope="col" class="py-3 pr-4 pl-4 font-semibold">
                            {{ $t('analytics.dashboard.channel') }}
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column"
                            scope="col"
                            class="px-3 py-3 text-right font-semibold"
                            :aria-sort="
                                sortBy === column ? 'descending' : 'none'
                            "
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
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in sorted"
                        :key="row.social_account_key"
                        class="border-b border-foreground/15 last:border-0 hover:bg-violet-50/60"
                    >
                        <th scope="row" class="py-3 pr-4 pl-4 font-normal">
                            <AccountIdentity :account="row" />
                        </th>
                        <td
                            v-for="column in columns"
                            :key="column"
                            class="px-3 py-3 text-right tabular-nums"
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
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AnalyticsSection>
</template>
