<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import SubscriptionsChurnTab from '@/components/admin/subscriptions/SubscriptionsChurnTab.vue';
import SubscriptionsOverviewTab from '@/components/admin/subscriptions/SubscriptionsOverviewTab.vue';
import SubscriptionsTableTab from '@/components/admin/subscriptions/SubscriptionsTableTab.vue';
import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import SettingsSearch from '@/components/settings/SettingsSearch.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUrlTab } from '@/composables/admin/useUrlTab';
import date from '@/date';
import debounce from '@/debounce';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { countIn } from '@/lib/adminFormat';
import { filled } from '@/lib/helpers';
import { formatFullNumber } from '@/lib/utils';
import { index as subscriptionsIndex } from '@/routes/admin/billing/subscriptions';
import type {
    AdminNetMrr,
    AdminSubscription,
    AdminSubscriptionChurn,
    AdminSubscriptionOverview,
    ScrollPage,
    SubscriptionStatusFilter,
} from '@/types/admin';
import type { AnalyticsFilters, AnalyticsRangePreset, DateRange } from '@/types/analytics';

type SubscriptionsTab = 'overview' | 'subscriptions' | 'churn';

const TABS: { value: SubscriptionsTab; label: string }[] = [
    { value: 'overview', label: 'Overview' },
    { value: 'subscriptions', label: 'Subscriptions' },
    { value: 'churn', label: 'Churn' },
];

const STATUS_FILTERS: { value: SubscriptionStatusFilter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'cancelling', label: 'Cancelling' },
    { value: 'past_due', label: 'Past due' },
    { value: 'trialing', label: 'Trialing' },
    { value: 'canceled', label: 'Canceled' },
];

const props = defineProps<{
    overview: AdminSubscriptionOverview;
    netMrr?: AdminNetMrr;
    churn: AdminSubscriptionChurn;
    subscriptions: ScrollPage<AdminSubscription>;
    range: DateRange;
    ranges: Exclude<AnalyticsRangePreset, 'custom'>[];
    bounds: { min: string | null; max: string | null };
    filters: AnalyticsFilters & { status: SubscriptionStatusFilter; search: string };
    statusCounts: Partial<Record<SubscriptionStatusFilter, number>>;
}>();

/** Overview opens by default. */
const { tab, selectTab } = useUrlTab({
    tabs: TABS.map((option) => option.value),
    url: (mergeQuery) => subscriptionsIndex.url({ mergeQuery }),
});

const showsRange = computed(() => tab.value !== 'subscriptions');

const keepTab = computed((): Record<string, string> => ({ tab: tab.value }));

const rangeSubtitle = computed(
    (): string => date.formatDayMonthYearRange(props.range),
);

const searchQuery = ref(props.filters.search);

/** The search as the URL carries it; an empty one leaves the URL. */
const searchParam = (): string | null => {
    if (filled(searchQuery.value)) {
        return searchQuery.value;
    }

    return null;
};

/** Reloads the list on the current range, so the range picker keeps it. */
const reloadTable = (): void => {
    router.get(
        subscriptionsIndex.url({
            mergeQuery: { tab: 'subscriptions', status: status.value, search: searchParam() },
        }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['subscriptions', 'filters', 'statusCounts'],
            reset: ['subscriptions'],
        },
    );
};

const { tab: status, selectTab: selectStatus } = useUrlTab({
    tabs: STATUS_FILTERS.map((filter) => filter.value),
    initial: props.filters.status,
    param: 'status',
    onSelect: reloadTable,
});

const search = debounce(reloadTable, 300);

const statusCount = (value: SubscriptionStatusFilter): string => formatFullNumber(countIn(props.statusCounts, value));

watch(searchQuery, () => search());
</script>

<template>
    <Head title="Subscriptions" />

    <AdminLayout
        title="Subscriptions"
        description="Who pays, what they pay for, and who leaves."
    >
        <Tabs
            :model-value="tab"
            class="flex flex-col gap-6"
            data-testid="admin-subscriptions"
            @update:model-value="selectTab"
        >
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-border">
                <TabsList variant="line" class="border-b-0">
                    <TabsTrigger
                        v-for="option in TABS"
                        :key="option.value"
                        :value="option.value"
                        :data-testid="`admin-subscriptions-tab-${option.value}`"
                    >
                        {{ option.label }}
                    </TabsTrigger>
                </TabsList>
                <div v-if="showsRange" class="pb-2">
                    <AnalyticsRangePresets
                        :filters="filters"
                        :bounds="bounds"
                        :url="subscriptionsIndex.url()"
                        :range="range"
                        :presets="ranges"
                        :keep="keepTab"
                    />
                </div>
            </div>

            <TabsContent value="overview">
                <SubscriptionsOverviewTab
                    :overview="overview"
                    :net-mrr="netMrr"
                    :subtitle="rangeSubtitle"
                />
            </TabsContent>

            <TabsContent value="subscriptions" class="flex flex-col gap-4">
                <Tabs :model-value="status" @update:model-value="selectStatus">
                    <TabsList>
                        <TabsTrigger
                            v-for="filter in STATUS_FILTERS"
                            :key="filter.value"
                            :value="filter.value"
                            :data-testid="`admin-subscriptions-filter-${filter.value}`"
                        >
                            {{ filter.label }}
                            <span class="text-xs text-muted-foreground tabular-nums">{{
                                statusCount(filter.value)
                            }}</span>
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
                <SettingsSearch v-model="searchQuery" placeholder="Search by name or email" />
                <SubscriptionsTableTab
                    :subscriptions="subscriptions"
                    :search="filters.search"
                    :status="filters.status"
                />
            </TabsContent>

            <TabsContent value="churn">
                <SubscriptionsChurnTab :churn="churn" :subtitle="rangeSubtitle" />
            </TabsContent>
        </Tabs>
    </AdminLayout>
</template>
