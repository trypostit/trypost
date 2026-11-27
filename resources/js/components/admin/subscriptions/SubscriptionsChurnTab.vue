<script setup lang="ts">
import { computed, ref } from 'vue';

import AdminBarList from '@/components/admin/AdminBarList.vue';
import AdminCustomerCell from '@/components/admin/AdminCustomerCell.vue';
import AdminDailyBarChart, {
    type AdminChartSeries,
} from '@/components/admin/AdminDailyBarChart.vue';
import AdminDailyLineChart from '@/components/admin/AdminDailyLineChart.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import date from '@/date';
import dayjs from '@/dayjs';
import { countLabel } from '@/lib/adminFormat';
import { subscriptionPlanLabel } from '@/lib/adminSubscriptions';
import { billingUrl } from '@/lib/adminUsers';
import { filled, transform } from '@/lib/helpers';
import { formatFullNumber, formatPercent } from '@/lib/utils';
import type {
    AdminBarRow,
    AdminStat,
    AdminSubscriptionChurn,
} from '@/types/admin';

type Cancellation = AdminSubscriptionChurn['recent'][number];

const props = defineProps<{
    churn: AdminSubscriptionChurn;
    subtitle: string;
}>();

/** How many cancellations show before "Show all". */
const COLLAPSED_ROWS = 8;

const hasRecent = computed(() => filled(props.churn.recent));

const showsAll = ref(false);

const visibleRecent = computed(() => {
    if (showsAll.value) {
        return props.churn.recent;
    }

    return props.churn.recent.slice(0, COLLAPSED_ROWS);
});

const hasMore = computed(() => !showsAll.value && props.churn.recent.length > COLLAPSED_ROWS);

const showAll = (): void => {
    showsAll.value = true;
};

const showAllLabel = computed((): string => `Show all ${formatFullNumber(props.churn.recent.length)}`);

const summary = computed((): AdminStat[] => [
    {
        key: 'churn-canceled',
        label: 'Canceled',
        value: formatFullNumber(props.churn.summary.canceled),
    },
    {
        key: 'churn-rate',
        label: 'Churn',
        value: formatPercent(props.churn.summary.churn_rate, 1),
        hint: 'Of the subscriptions alive at the start',
    },
    {
        key: 'churn-lifetime',
        label: 'Average lifetime',
        value: countLabel(props.churn.summary.average_lifetime_days, 'day', 'days'),
        hint: 'Of those canceled in the period',
    },
    {
        key: 'churn-cancelling',
        label: 'Cancelling now',
        value: formatFullNumber(props.churn.summary.cancelling),
        hint: 'Leave at their period end',
    },
]);

const canceledSeries = computed((): AdminChartSeries[] => [
    {
        key: 'canceled',
        label: 'Canceled',
        color: 'var(--destructive)',
        values: props.churn.canceled_by_day,
    },
]);

const lifetimeRows = computed((): AdminBarRow[] =>
    props.churn.lifetimes.map((lifetime) => ({
        key: lifetime.label,
        label: lifetime.label,
        count: lifetime.count,
        barClass: 'bg-destructive/70',
    })),
);

const lasted = (cancellation: Cancellation): string => countLabel(cancellation.lifetime_days, 'day', 'days');

const customerUrl = (cancellation: Cancellation): string | null => transform(cancellation.user_id, billingUrl);

/** A cohort's `YYYY-MM` as "Nov 2026". */
const cohortMonth = (month: string): string => {
    const start = dayjs(`${month}-01`);

    return date.formatMonthYear(start.month() + 1, start.year());
};

const retentionClass = (retention: number): string => {
    if (retention >= 50) {
        return 'bg-success';
    }

    if (retention >= 25) {
        return 'bg-warning';
    }

    return 'bg-destructive';
};

/** Each start month, its bar the share still paying, colored by how much is left. */
const cohortRows = computed((): AdminBarRow[] =>
    props.churn.cohorts.map((cohort) => ({
        key: cohort.month,
        label: cohortMonth(cohort.month),
        count: cohort.alive,
        hint: `${formatFullNumber(cohort.alive)} of ${formatFullNumber(cohort.started)}`,
        share: cohort.retention,
        bar: cohort.retention,
        barClass: retentionClass(cohort.retention),
    })),
);
</script>

<template>
    <div class="flex flex-col gap-6" data-testid="admin-subscriptions-churn">
        <AnalyticsSection title="Churn" :subtitle="subtitle" flush>
            <AdminStatGrid :stats="summary" />
            <div class="border-t border-border px-4 py-4 sm:px-5">
                <p class="mb-2 text-xs font-medium text-muted-foreground">
                    Churn rate over the 30 days before each day
                </p>
                <AdminDailyLineChart
                    :days="churn.days"
                    :values="churn.churn_rate_by_day"
                    label="Churn"
                    color="var(--destructive)"
                    percent
                    test-id="admin-subscriptions-churn-rate"
                />
            </div>
        </AnalyticsSection>

        <AnalyticsSection title="Canceled per day" :subtitle="subtitle">
            <AdminDailyBarChart
                :days="churn.days"
                :series="canceledSeries"
                test-id="admin-subscriptions-canceled-per-day"
            />
        </AnalyticsSection>

        <div class="grid gap-6 lg:grid-cols-2">
            <AdminBarList
                title="How long canceled subscriptions lasted"
                subtitle="Every subscription that ended"
                :rows="lifetimeRows"
                label-class="w-32"
                empty="No subscription ended yet."
                test-id="admin-subscriptions-lifetimes"
            />

            <AdminBarList
                title="Retention by start month"
                subtitle="Accounts still paying and not cancelling, by the month they first subscribed"
                :rows="cohortRows"
                label-class="w-24"
                empty="Nobody subscribed yet."
                test-id="admin-subscriptions-cohorts"
            >
                <template #value="{ row }">
                    <span class="w-14 shrink-0 text-right text-sm font-medium text-foreground tabular-nums">{{
                        formatPercent(row.share, 1)
                    }}</span>
                </template>
            </AdminBarList>
        </div>

        <AnalyticsSection title="Canceled in the period" :subtitle="subtitle" flush>
            <AdminEmptyState v-if="!hasRecent" variant="section">Nobody canceled in this period.</AdminEmptyState>
            <div v-else class="overflow-x-auto border-t border-border">
                <Table data-testid="admin-subscriptions-canceled">
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="h-9 border-r-0 px-4 text-xs sm:px-5">Customer</TableHead>
                            <TableHead class="h-9 border-r-0 px-4 text-xs">Plan</TableHead>
                            <TableHead class="h-9 border-r-0 px-4 text-xs">Offer</TableHead>
                            <TableHead class="h-9 border-r-0 px-4 text-right text-xs">Lasted</TableHead>
                            <TableHead class="h-9 border-r-0 px-4 text-right text-xs sm:px-5">Ended</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="cancellation in visibleRecent"
                            :key="cancellation.id"
                            class="last:border-b-0"
                        >
                            <TableCell class="max-w-56 border-r-0 px-4 py-2 sm:px-5">
                                <AdminCustomerCell
                                    :name="cancellation.user_name"
                                    :href="customerUrl(cancellation)"
                                    :detail="cancellation.user_email"
                                />
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-2 text-sm whitespace-nowrap text-muted-foreground">
                                {{ subscriptionPlanLabel(cancellation.plan) }}
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-2">
                                <span class="flex gap-1">
                                    <Badge
                                        v-for="offer in cancellation.offers"
                                        :key="offer"
                                        variant="secondary"
                                        class="font-mono"
                                        >{{ offer }}</Badge
                                    >
                                </span>
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-2 text-right text-sm whitespace-nowrap text-foreground tabular-nums">
                                {{ lasted(cancellation) }}
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-2 text-right text-sm whitespace-nowrap text-muted-foreground sm:px-5">
                                {{ date.formatDate(cancellation.ends_at) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <div v-if="hasMore" class="border-t border-border px-4 py-2 text-center sm:px-5">
                    <Button variant="ghost" size="sm" data-testid="admin-subscriptions-canceled-all" @click="showAll">
                        {{ showAllLabel }}
                    </Button>
                </div>
            </div>
        </AnalyticsSection>
    </div>
</template>
