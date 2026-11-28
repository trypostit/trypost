<script setup lang="ts">
import { IconArrowRight } from '@tabler/icons-vue';
import { computed } from 'vue';

import AdminBarList from '@/components/admin/shared/AdminBarList.vue';
import AdminCustomerCell from '@/components/admin/shared/AdminCustomerCell.vue';
import AdminEmptyState from '@/components/admin/shared/AdminEmptyState.vue';
import AdminStatGrid from '@/components/admin/shared/AdminStatGrid.vue';
import ChurnSignalChip from '@/components/admin/shared/ChurnSignalChip.vue';
import RiskLevelLabel from '@/components/admin/shared/RiskLevelLabel.vue';
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
import { churnSignal, isChurnSignal } from '@/lib/admin/churnRisk';
import { countIn, formatUsd } from '@/lib/admin/format';
import { profileUrl } from '@/lib/admin/users';
import { filled, transform } from '@/lib/helpers';
import { formatFullNumber, formatPercent, percentOf } from '@/lib/utils';
import type { AdminBarRow, AdminStat } from '@/types/admin/shared';
import type { AdminChurnRisk } from '@/types/admin/userInsights';

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
        value: formatUsd(props.churn.mrr_at_risk_cents),
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

const levelsHint = computed(
    (): string =>
        `${formatFullNumber(countIn(props.churn.levels, 'high'))} high, ${formatFullNumber(countIn(props.churn.levels, 'medium'))} medium`,
);

/** One row per signal, most frequent first as the server counted them; a signal this page does not know yet is left out. */
const signalRows = computed((): AdminBarRow[] =>
    Object.keys(props.churn.signals)
        .filter(isChurnSignal)
        .map((signal) => {
            const count = countIn(props.churn.signals, signal);

            return {
                key: signal,
                label: churnSignal(signal).label,
                count,
                share: percentOf(count, props.churn.at_risk),
            };
        }),
);

const hasAtRisk = computed(() => filled(props.churn.accounts));

const customerUrl = (row: RiskRow): string | null => transform(row.user_id, profileUrl);
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
            <AdminEmptyState v-if="!hasAtRisk" variant="section">Nobody looks at risk.</AdminEmptyState>
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
                                <AdminCustomerCell :name="row.name" :href="customerUrl(row)" :detail="row.email" />
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
                                {{ formatUsd(row.mrr_cents) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </AnalyticsSection>
    </div>
</template>
