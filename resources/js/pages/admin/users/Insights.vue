<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

import ChurnRiskTab from '@/components/admin/user-insights/ChurnRiskTab.vue';
import TopAccountsTab from '@/components/admin/user-insights/TopAccountsTab.vue';
import UsageOverviewTab from '@/components/admin/user-insights/UsageOverviewTab.vue';
import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUrlTab } from '@/composables/admin/useUrlTab';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatFullNumber } from '@/lib/utils';
import { index as insightsIndex } from '@/routes/admin/users/insights';
import type { AdminChurnRisk, AdminUsageInsights } from '@/types/admin';
import type { AnalyticsFilters, AnalyticsRangePreset, DateRange } from '@/types/analytics';

const props = defineProps<{
    usage: AdminUsageInsights;
    churn: AdminChurnRisk;
    range: DateRange;
    ranges: Exclude<AnalyticsRangePreset, 'custom'>[];
    bounds: { min: string | null; max: string | null };
    filters: AnalyticsFilters;
}>();

type InsightsTab = 'overview' | 'top' | 'churn';

const TABS: { value: InsightsTab; label: string }[] = [
    { value: 'overview', label: 'Overview' },
    { value: 'top', label: 'Top accounts' },
    { value: 'churn', label: 'Churn risk' },
];

/** Overview opens by default. */
const { tab, selectTab } = useUrlTab({
    tabs: TABS.map((option) => option.value),
    url: (mergeQuery) => insightsIndex.url({ mergeQuery }),
});

const keepTab = computed((): Record<string, string> => ({ tab: tab.value }));

const rangeLabel = computed((): string => date.formatDayMonthYearRange(props.range));

const subtitle = computed((): string => `${rangeLabel.value} · against the period before`);

const atRiskCount = computed((): string => formatFullNumber(props.churn.at_risk));

/** Churn risk carries how many accounts are at risk. */
const showsCount = (value: InsightsTab): boolean => value === 'churn';
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
                <TabsTrigger
                    v-for="option in TABS"
                    :key="option.value"
                    :value="option.value"
                    :data-testid="`admin-user-insights-tab-${option.value}`"
                >
                    {{ option.label }}
                    <span v-if="showsCount(option.value)" class="text-xs text-muted-foreground tabular-nums">{{
                        atRiskCount
                    }}</span>
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
