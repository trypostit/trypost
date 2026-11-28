<script setup lang="ts">
import { computed, ref } from 'vue';

import AdminBarList from '@/components/admin/AdminBarList.vue';
import AdminDailyBarChart, {
    type AdminChartSeries,
} from '@/components/admin/AdminDailyBarChart.vue';
import AdminDailyLineChart from '@/components/admin/AdminDailyLineChart.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import AdminMetricTabs from '@/components/admin/AdminMetricTabs.vue';
import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { metricChange } from '@/lib/adminChange';
import { countIn, countLabel, formatUsd } from '@/lib/adminFormat';
import { subscriptionPlanLabel } from '@/lib/adminSubscriptions';
import { accountColor } from '@/lib/analyticsColors';
import { blank, filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent, formatSignedNumber, MISSING_VALUE, percentOf } from '@/lib/utils';
import type {
    AdminBarRow,
    AdminMetricTab,
    AdminNetMrr,
    AdminStat,
    AdminSubscriptionOverview,
} from '@/types/admin';

const props = defineProps<{
    overview: AdminSubscriptionOverview;
    /** Read from Stripe after the page loads; undefined while it loads. */
    netMrr?: AdminNetMrr;
    subtitle: string;
}>();

const LINE_COLOR = accountColor(0);
const NEW_COLOR = 'var(--success)';
const CANCELED_COLOR = 'var(--destructive)';

const now = computed((): AdminStat[] => [
    {
        key: 'subs-mrr',
        label: 'MRR (list price)',
        value: formatUsd(props.overview.mrr.cents),
        hint: mrrHint.value,
        change: metricChange(props.overview.mrr.cents, props.overview.previous.mrr_cents),
    },
    {
        key: 'subs-mrr-net',
        label: 'MRR (after coupons)',
        value: netMrrValue.value,
        hint: netMrrHint.value,
        loading: isReadingNetMrr.value,
    },
    {
        key: 'subs-past-due',
        label: 'Past due',
        value: `${formatUsd(props.overview.at_risk.past_due_cents)}/mo`,
        hint: countLabel(props.overview.now.past_due, 'subscription unpaid', 'subscriptions unpaid'),
    },
    {
        key: 'subs-cancelling',
        label: 'Cancelling',
        value: `${formatUsd(props.overview.at_risk.cancelling_cents)}/mo`,
        hint: countLabel(props.overview.now.cancelling, 'subscription cancelling', 'subscriptions cancelling'),
    },
]);

const readNetMrr = (netMrr: AdminNetMrr): string => {
    if ('error' in netMrr) {
        return MISSING_VALUE;
    }

    return formatUsd(netMrr.cents);
};

const readNetMrrHint = (netMrr: AdminNetMrr): string => {
    if ('error' in netMrr) {
        return "Couldn't read Stripe";
    }

    return 'Coupons taken off';
};

/** Stripe is read after the page loads, so the figure pulses until it arrives. */
const isReadingNetMrr = computed(() => blank(props.netMrr));

const netMrrValue = computed((): string => {
    if (filled(props.netMrr)) {
        return readNetMrr(props.netMrr);
    }

    return '';
});

const netMrrHint = computed((): string => {
    if (filled(props.netMrr)) {
        return readNetMrrHint(props.netMrr);
    }

    return 'Reading Stripe…';
});

const mrrHint = computed((): string => {
    if (props.overview.mrr.unpriced > 0) {
        return `Coupons not taken off; ${props.overview.mrr.unpriced} without a known price`;
    }

    return 'Coupons not taken off';
});

type TrendView = 'alive' | 'new' | 'canceled' | 'net' | 'churn';

/** The total opens first: it answers whether subscriptions are growing. */
const trendView = ref<TrendView>('alive');

const trends = computed((): AdminMetricTab<TrendView>[] => [
    {
        value: 'alive',
        label: 'Subscriptions',
        figure: formatFullNumber(props.overview.now.alive),
        change: metricChange(props.overview.now.alive, props.overview.period.alive_at_start),
    },
    {
        value: 'new',
        label: 'New',
        figure: formatFullNumber(props.overview.period.new),
        change: metricChange(props.overview.period.new, props.overview.previous.new),
    },
    {
        value: 'canceled',
        label: 'Canceled',
        figure: formatFullNumber(props.overview.period.canceled),
        change: metricChange(props.overview.period.canceled, props.overview.previous.canceled, true),
    },
    {
        value: 'net',
        label: 'Net',
        figure: formatSignedNumber(props.overview.period.new - props.overview.period.canceled),
    },
    {
        value: 'churn',
        label: 'Churn',
        figure: formatPercent(props.overview.period.churn_rate, 1),
        change: metricChange(props.overview.period.churn_rate, props.overview.previous.churn_rate, true),
    },
]);

const isTrend = (value: TrendView): boolean => trendView.value === value;

const TREND_NOTES: Record<TrendView, string> = {
    alive: 'alive at the end of each day',
    new: 'started per day',
    canceled: 'ended per day',
    net: 'new minus canceled, added up since the start of the period',
    churn: 'share that ended in the 30 days before each day',
};

const trendSubtitle = computed(
    (): string =>
        `${props.subtitle} · ${TREND_NOTES[trendView.value]} · changes against the period before`,
);

/** The period's net, added up day by day from zero. */
const netByDay = computed((): number[] => {
    let total = 0;

    return props.overview.new_by_day.map((count, index) => {
        total += count - countIn(props.overview.canceled_by_day, index);

        return total;
    });
});

const newSeries = computed((): AdminChartSeries[] => [
    { key: 'new', label: 'New', color: NEW_COLOR, values: props.overview.new_by_day },
]);

const canceledSeries = computed((): AdminChartSeries[] => [
    { key: 'canceled', label: 'Canceled', color: CANCELED_COLOR, values: props.overview.canceled_by_day },
]);

const planRows = computed((): AdminBarRow[] =>
    props.overview.plans.map((plan) => ({
        key: `${plan.plan}-${plan.interval}`,
        label: subscriptionPlanLabel(plan),
        count: plan.count,
        share: percentOf(plan.count, props.overview.now.alive),
    })),
);

const hasOffers = computed(() => filled(props.overview.offers));

const offerRows = computed(() =>
    props.overview.offers.map((offer) => ({
        slug: offer.slug,
        redeemed: `${formatFullNumber(offer.redeemed)} redeemed`,
        retained: `${formatFullNumber(offer.still_subscribed)} · ${formatPercent(percentOf(offer.still_subscribed, offer.redeemed), 1)}`,
    })),
);
</script>

<template>
    <div class="flex flex-col gap-6" data-testid="admin-subscriptions-overview">
        <AnalyticsSection
            title="Right now"
            subtitle="MRR change against the start of the period"
            flush
        >
            <AdminStatGrid :stats="now" />
        </AnalyticsSection>

        <AnalyticsSection title="Subscriptions over time" :subtitle="trendSubtitle" flush>
            <AdminMetricTabs v-model="trendView" :tabs="trends" test-id="admin-subscriptions-trend" />
            <div class="border-t border-border px-4 py-4 sm:px-5">
                <AdminDailyLineChart
                    v-if="isTrend('alive')"
                    :days="overview.days"
                    :values="overview.alive_by_day"
                    label="Subscriptions"
                    :color="LINE_COLOR"
                    test-id="admin-subscriptions-alive"
                />
                <AdminDailyBarChart
                    v-else-if="isTrend('new')"
                    :days="overview.days"
                    :series="newSeries"
                    test-id="admin-subscriptions-new"
                />
                <AdminDailyBarChart
                    v-else-if="isTrend('canceled')"
                    :days="overview.days"
                    :series="canceledSeries"
                    test-id="admin-subscriptions-canceled"
                />
                <AdminDailyLineChart
                    v-else-if="isTrend('net')"
                    :days="overview.days"
                    :values="netByDay"
                    label="Net"
                    :color="NEW_COLOR"
                    test-id="admin-subscriptions-net"
                />
                <AdminDailyLineChart
                    v-else
                    :days="overview.days"
                    :values="overview.churn_rate_by_day"
                    label="Churn"
                    :color="CANCELED_COLOR"
                    percent
                    test-id="admin-subscriptions-churn"
                />
            </div>
        </AnalyticsSection>

        <div class="grid gap-6 lg:grid-cols-2">
            <AdminBarList
                title="By plan"
                subtitle="Subscriptions alive now"
                :rows="planRows"
                label-class="w-40"
                empty="No subscription alive now."
                test-id="admin-subscriptions-plans"
            />

            <AnalyticsSection title="Offers" subtitle="Redeemed, and still subscribed now" flush>
                <AdminEmptyState v-if="!hasOffers" variant="section">No offer redeemed yet.</AdminEmptyState>
                <ul v-else class="divide-y divide-border border-t border-border" data-testid="admin-subscriptions-offers">
                    <li
                        v-for="offer in offerRows"
                        :key="offer.slug"
                        class="flex items-center gap-3 px-4 py-2.5 sm:px-5"
                    >
                        <code class="min-w-0 flex-1 truncate font-mono text-sm text-foreground">{{
                            offer.slug
                        }}</code>
                        <span class="text-sm text-muted-foreground tabular-nums">{{ offer.redeemed }}</span>
                        <span class="w-24 text-right text-sm font-medium text-foreground tabular-nums">{{
                            offer.retained
                        }}</span>
                    </li>
                </ul>
            </AnalyticsSection>
        </div>
    </div>
</template>
