<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowRight } from '@tabler/icons-vue';
import { computed } from 'vue';

import AdminBarList from '@/components/admin/AdminBarList.vue';
import AdminStatGrid, { type AdminStat } from '@/components/admin/AdminStatGrid.vue';
import ChurnSignalChip from '@/components/admin/ChurnSignalChip.vue';
import RiskLevelLabel from '@/components/admin/RiskLevelLabel.vue';
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
import { usd } from '@/lib/adminSubscriptions';
import { churnSignal } from '@/lib/churnRisk';
import { filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent, percentOf } from '@/lib/utils';
import { show as userShow } from '@/routes/admin/users';
import type { AdminBarRow, AdminChurnRisk } from '@/types/admin';

type RiskRow = AdminChurnRisk['accounts'][number];
const props = defineProps<{
    churn: AdminChurnRisk;
}>();

/** Read left to right like a funnel: every figure is out of the same paying accounts. */
const stats = computed((): AdminStat[] => [
    {
        key: 'churn-base',
        label: 'Subscribed accounts',
        value: formatFullNumber(props.churn.base),
        hint: 'Paying now, past due included',
    },
    {
        key: 'churn-active',
        label: 'Active subscribers',
        value: formatFullNumber(props.churn.active),
        hint: `${formatPercent(percentOf(props.churn.active, props.churn.base), 1)} sent a post in the period`,
    },
    {
        key: 'churn-at-risk',
        label: 'At risk',
        value: formatFullNumber(props.churn.at_risk),
        hint: `${formatPercent(props.churn.at_risk_share, 1)} · ${levelsHint.value}`,
    },
    {
        key: 'churn-mrr',
        label: 'MRR at risk',
        value: usd(props.churn.mrr_at_risk_cents),
        hint: 'At list price',
    },
]);

const lastPost = (row: RiskRow): string => {
    if (filled(row.last_posted_at)) {
        return date.diffForHumans(row.last_posted_at);
    }

    return 'Never';
};

/** Posting fell when the period sent fewer posts than the one before. */
const postsClass = (row: RiskRow): string => {
    if (row.posts < row.previous_posts) {
        return 'text-destructive';
    }

    return 'text-foreground';
};

const levelsHint = computed((): string => {
    const levels = props.churn.levels;

    return `${formatFullNumber(levels.high ?? 0)} high, ${formatFullNumber(levels.medium ?? 0)} medium`;
});

const signalRows = computed((): AdminBarRow[] =>
    Object.entries(props.churn.signals).map(([key, count]) => ({
        key,
        label: churnSignal(key).label,
        count,
        share: percentOf(count, props.churn.at_risk),
    })),
);

const hasAtRisk = computed(() => filled(props.churn.accounts));

const nameOf = (row: RiskRow): string => {
    if (filled(row.name)) {
        return row.name;
    }

    return 'Deleted user';
};

const profileUrl = (userId: string): string => userShow.url(userId);
</script>

<template>
    <div class="flex flex-col gap-6" data-testid="admin-user-insights-churn">
        <AnalyticsSection
            title="Churn risk"
            subtitle="Paying accounts with a sign they may leave · posting is measured in the period, against the period before"
            flush
        >
            <AdminStatGrid :stats="stats" />
        </AnalyticsSection>

        <AdminBarList
            title="Why they are at risk"
            subtitle="An account can show several signals"
            :rows="signalRows"
            test-id="admin-user-insights-signals"
        />

        <AnalyticsSection
            title="Accounts at risk"
            subtitle="Highest risk first, then what they pay · posts are the period before → this period"
            flush
        >
            <p
                v-if="!hasAtRisk"
                class="border-t border-border px-4 py-6 text-center text-sm text-muted-foreground sm:px-5"
            >
                Nobody looks at risk.
            </p>
            <div v-else class="overflow-x-auto border-t border-border">
                <Table data-testid="admin-user-insights-at-risk">
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="h-10 border-r-0 px-4 sm:px-5">Customer</TableHead>
                            <TableHead class="h-10 border-r-0 px-4">Risk</TableHead>
                            <TableHead class="h-10 border-r-0 px-4">Why</TableHead>
                            <TableHead class="h-10 border-r-0 px-4">Last post</TableHead>
                            <TableHead class="h-10 border-r-0 px-4 text-right">Posts</TableHead>
                            <TableHead class="h-10 border-r-0 px-4 text-right sm:px-5">MRR</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in churn.accounts" :key="row.account_id" class="last:border-b-0">
                            <TableCell class="max-w-56 border-r-0 px-4 py-3 align-top sm:px-5">
                                <Link
                                    v-if="row.user_id"
                                    :href="profileUrl(row.user_id)"
                                    class="block truncate text-sm font-emphasis text-foreground hover:underline"
                                    >{{ nameOf(row) }}</Link
                                >
                                <span v-else class="block truncate text-sm text-muted-foreground">{{
                                    nameOf(row)
                                }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ row.email }}</span>
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-3 align-top whitespace-nowrap">
                                <RiskLevelLabel :level="row.level" />
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-3 align-top whitespace-normal">
                                <span class="flex flex-wrap gap-1">
                                    <ChurnSignalChip v-for="key in row.signals" :key="key" :signal="key" />
                                </span>
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-3 align-top text-sm whitespace-nowrap text-muted-foreground">
                                {{ lastPost(row) }}
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-3 text-right align-top whitespace-nowrap">
                                <span class="inline-flex items-center gap-1 text-sm tabular-nums">
                                    <span class="text-muted-foreground">{{ formatFullNumber(row.previous_posts) }}</span>
                                    <IconArrowRight class="size-3 text-muted-foreground" aria-hidden="true" />
                                    <span class="font-medium" :class="postsClass(row)">{{
                                        formatFullNumber(row.posts)
                                    }}</span>
                                </span>
                            </TableCell>
                            <TableCell
                                class="border-r-0 px-4 py-3 text-right align-top text-sm font-medium whitespace-nowrap text-foreground tabular-nums sm:px-5"
                            >
                                {{ usd(row.mrr_cents) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </AnalyticsSection>
    </div>
</template>
