<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ChurnRiskTab from '@/components/admin/user-insights/ChurnRiskTab.vue';
import TopAccountsTab from '@/components/admin/user-insights/TopAccountsTab.vue';
import UsageOverviewTab from '@/components/admin/user-insights/UsageOverviewTab.vue';
import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatFullNumber } from '@/lib/utils';
import { index as insightsIndex } from '@/routes/admin/users/insights';
import type { AdminChurnRisk, AdminUsageInsights } from '@/types/admin';
import type { AnalyticsFilters, AnalyticsRangePreset, DateRange } from '@/types/analytics';

type InsightsTab = 'overview' | 'top' | 'churn';

const props = defineProps<{
    usage: AdminUsageInsights;
    churn: AdminChurnRisk;
    range: DateRange;
    ranges: Exclude<AnalyticsRangePreset, 'custom'>[];
    bounds: { min: string | null; max: string | null };
    filters: AnalyticsFilters;
}>();

const page = usePage();

/** Overview opens by default. */
const insightsTab = (value: unknown): InsightsTab => {
    if (value === 'top') {
        return 'top';
    }

    if (value === 'churn') {
        return 'churn';
    }

    return 'overview';
};

/** The open tab is read from the URL, like the Billing tabs. */
const tab = ref<InsightsTab>(
    insightsTab(new URL(page.url, window.location.origin).searchParams.get('tab')),
);

const selectTab = (value: string | number): void => {
    tab.value = insightsTab(value);

    router.replace({
        url: insightsIndex.url({ mergeQuery: { tab: tab.value } }),
        preserveScroll: true,
        preserveState: true,
    });
};

const keepTab = computed((): Record<string, string> => ({ tab: tab.value }));

const rangeLabel = computed((): string => date.formatDayMonthYearRange(props.range));

const subtitle = computed(
    (): string => `${date.formatDayMonthYearRange(props.range)} · against the period before`,
);

const atRiskCount = computed((): string => formatFullNumber(props.churn.at_risk));
</script>

<template>
    <Head title="People insights" />

    <AdminLayout title="Insights" description="How the accounts use TryPost, and which may leave.">
        <template #actions>
            <AnalyticsRangePresets
                :filters="filters"
                :bounds="bounds"
                :url="insightsIndex.url()"
                :range="range"
                :presets="ranges"
                :keep="keepTab"
            />
        </template>

        <Tabs
            :model-value="tab"
            class="flex flex-col gap-6"
            data-testid="admin-user-insights"
            @update:model-value="selectTab"
        >
            <TabsList variant="line">
                <TabsTrigger value="overview" data-testid="admin-user-insights-tab-overview">Overview</TabsTrigger>
                <TabsTrigger value="top" data-testid="admin-user-insights-tab-top">Top accounts</TabsTrigger>
                <TabsTrigger value="churn" data-testid="admin-user-insights-tab-churn">
                    Churn risk
                    <span class="text-xs text-muted-foreground tabular-nums">{{ atRiskCount }}</span>
                </TabsTrigger>
            </TabsList>

            <TabsContent value="overview">
                <UsageOverviewTab :usage="usage" :subtitle="subtitle" />
            </TabsContent>

            <TabsContent value="top">
                <TopAccountsTab :accounts="usage.top_accounts" :subtitle="rangeLabel" />
            </TabsContent>

            <TabsContent value="churn">
                <ChurnRiskTab :churn="churn" />
            </TabsContent>
        </Tabs>
    </AdminLayout>
</template>
