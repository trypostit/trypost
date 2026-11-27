<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AdminDailyBarChart, {
    type AdminChartSeries,
} from '@/components/admin/AdminDailyBarChart.vue';
import AdminDailyLineChart from '@/components/admin/AdminDailyLineChart.vue';
import AdminStatGrid, { type AdminStat } from '@/components/admin/AdminStatGrid.vue';
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
import { planLabel } from '@/lib/adminSubscriptions';
import { filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent } from '@/lib/utils';
import { show as userShow } from '@/routes/admin/users';
import type { AdminSubscriptionChurn } from '@/types/admin';

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
        value: `${formatFullNumber(props.churn.summary.average_lifetime_days)} days`,
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

const maxLifetime = computed(() =>
    Math.max(1, ...props.churn.lifetimes.map((lifetime) => lifetime.count)),
);

const barWidth = (value: number, max: number): string => `${(value / max) * 100}%`;

const lasted = (cancellation: Cancellation): string => {
    if (cancellation.lifetime_days === 1) {
        return '1 day';
    }

    return `${formatFullNumber(cancellation.lifetime_days)} days`;
};

const customer = (cancellation: Cancellation): string => {
    if (filled(cancellation.user_name)) {
        return cancellation.user_name;
    }

    return 'Deleted user';
};

const billingUrl = (userId: string): string =>
    userShow.url(userId, { query: { tab: 'billing' } });

/** A cohort's `YYYY-MM` as "Nov 2026". */
const cohortMonth = (month: string): string => {
    const [year, monthNumber] = month.split('-').map(Number);

    return date.formatMonthYear(monthNumber, year);
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
            <AnalyticsSection title="How long canceled subscriptions lasted" subtitle="Every subscription that ended" flush>
                <ul class="flex flex-col border-t border-border py-2" data-testid="admin-subscriptions-lifetimes">
                    <li
                        v-for="lifetime in churn.lifetimes"
                        :key="lifetime.label"
                        class="flex items-center gap-3 px-4 py-1.5 sm:px-5"
                    >
                        <span class="w-32 shrink-0 truncate text-sm text-foreground">{{
                            lifetime.label
                        }}</span>
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                            <span
                                class="block h-full rounded-full bg-destructive/70"
                                :style="{ width: barWidth(lifetime.count, maxLifetime) }"
                            />
                        </span>
                        <span
                            class="w-10 shrink-0 text-right text-sm font-medium text-foreground tabular-nums"
                            >{{ formatFullNumber(lifetime.count) }}</span
                        >
                    </li>
                </ul>
            </AnalyticsSection>

            <AnalyticsSection title="Retention by start month" subtitle="Accounts still paying and not cancelling, by the month they first subscribed" flush>
                <ul class="flex flex-col border-t border-border py-2" data-testid="admin-subscriptions-cohorts">
                    <li
                        v-for="cohort in churn.cohorts"
                        :key="cohort.month"
                        class="flex items-center gap-3 px-4 py-1.5 sm:px-5"
                    >
                        <span class="w-24 shrink-0 truncate text-sm text-foreground">{{
                            cohortMonth(cohort.month)
                        }}</span>
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                            <span
                                class="block h-full rounded-full"
                                :class="retentionClass(cohort.retention)"
                                :style="{ width: barWidth(cohort.retention, 100) }"
                            />
                        </span>
                        <span class="w-20 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                            >{{ formatFullNumber(cohort.alive) }} of {{ formatFullNumber(cohort.started) }}</span
                        >
                        <span
                            class="w-12 shrink-0 text-right text-sm font-medium text-foreground tabular-nums"
                            >{{ formatPercent(cohort.retention, 1) }}</span
                        >
                    </li>
                </ul>
            </AnalyticsSection>
        </div>

        <AnalyticsSection title="Canceled in the period" :subtitle="subtitle" flush>
            <p
                v-if="!hasRecent"
                class="border-t border-border px-4 py-6 text-center text-sm text-muted-foreground sm:px-5"
            >
                Nobody canceled in this period.
            </p>
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
                                <Link
                                    v-if="cancellation.user_id"
                                    :href="billingUrl(cancellation.user_id)"
                                    class="block truncate text-sm text-foreground hover:underline"
                                    >{{ customer(cancellation) }}</Link
                                >
                                <span v-else class="block truncate text-sm text-muted-foreground">{{
                                    customer(cancellation)
                                }}</span>
                            </TableCell>
                            <TableCell class="border-r-0 px-4 py-2 text-sm whitespace-nowrap text-muted-foreground">
                                {{ planLabel(cancellation.plan) }}
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
                                {{ date.formatDate(cancellation.ended_at) }}
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
