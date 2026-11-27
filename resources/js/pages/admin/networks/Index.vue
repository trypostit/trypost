<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AdminBarRows from '@/components/admin/AdminBarRows.vue';
import AdminDailyBarChart, {
    type AdminChartSeries,
} from '@/components/admin/AdminDailyBarChart.vue';
import AdminDailyLineChart from '@/components/admin/AdminDailyLineChart.vue';
import AdminMetricTabs from '@/components/admin/AdminMetricTabs.vue';
import AdminSortableTable from '@/components/admin/AdminSortableTable.vue';
import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import NetworkPermissionsCard from '@/components/admin/NetworkPermissionsCard.vue';
import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUrlTab } from '@/composables/admin/useUrlTab';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ALARMING_FAILURE_RATE } from '@/lib/adminPublishing';
import { accountColor } from '@/lib/analyticsColors';
import { filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent, MISSING_VALUE, percentOf } from '@/lib/utils';
import { index as networksIndex } from '@/routes/admin/networks';
import type {
    AdminBarRow,
    AdminChannelDistribution,
    AdminChannelGrowth,
    AdminMetricTab,
    AdminNetworkHealth,
    AdminNetworkPermissions,
    AdminStat,
    AdminTableColumn,
    BadgeVariant,
} from '@/types/admin';
import type { AnalyticsFilters, DateRange } from '@/types/analytics';

const props = defineProps<{
    networks: AdminNetworkHealth[];
    distribution: AdminChannelDistribution;
    permissions: AdminNetworkPermissions[];
    growth: AdminChannelGrowth;
    range: DateRange;
    bounds: { min: string | null; max: string | null };
    filters: AnalyticsFilters;
}>();

type NetworksTab = 'health' | 'permissions';

const TABS: { value: NetworksTab; label: string }[] = [
    { value: 'health', label: 'Health' },
    { value: 'permissions', label: 'Permissions' },
];

/** Health opens by default. */
const { tab, selectTab } = useUrlTab({
    tabs: TABS.map((option) => option.value),
    url: (mergeQuery) => networksIndex.url({ mergeQuery }),
});

const growthSeries = computed((): AdminChartSeries[] =>
    Object.entries(props.growth.by_network).map(([network, perDay], index) => ({
        key: network,
        label: getPlatformLabel(network),
        color: accountColor(index),
        values: perDay,
    })),
);

type GrowthView = 'total' | 'new';

/** The total opens first: it answers whether the app is growing. */
const growthView = ref<GrowthView>('total');

const growthViews = computed((): AdminMetricTab<GrowthView>[] => [
    {
        value: 'total',
        label: 'Total channels now',
        figure: formatFullNumber(props.growth.connected_now),
        hint: 'Day by day, from the channels still connected',
    },
    {
        value: 'new',
        label: 'New channels in the period',
        figure: formatFullNumber(props.growth.new_in_range),
        hint: 'Per day, by first connection',
    },
]);

const isGrowthView = (value: GrowthView): boolean => growthView.value === value;

const growthSubtitle = computed((): string => date.formatDayMonthYearRange(props.range));

type DistributionView = 'channels' | 'accounts' | 'first';

/** Channels open first: the count the rest of the page reads. */
const distributionView = ref<DistributionView>('channels');

const distributionViews = computed((): AdminMetricTab<DistributionView>[] => [
    {
        value: 'channels',
        label: 'Connected channels',
        figure: formatFullNumber(props.distribution.totals.channels),
        hint: 'Every channel counted',
    },
    {
        value: 'accounts',
        label: 'Accounts using it',
        figure: formatFullNumber(props.distribution.totals.accounts),
        hint: 'An account counts on each network it uses',
    },
    {
        value: 'first',
        label: 'Network they started with',
        figure: formatFullNumber(props.distribution.totals.first),
        hint: 'Accounts, each counted once',
    },
]);

const perAccountHint = (perAccount: number): string =>
    `${formatFullNumber(perAccount)} per account`;

const distributionRows = computed((): AdminBarRow[] => {
    if (distributionView.value === 'accounts') {
        return props.distribution.accounts.map((row) => ({ ...row, hint: perAccountHint(row.per_account) }));
    }

    if (distributionView.value === 'first') {
        return props.distribution.first;
    }

    return props.distribution.channels;
});

const sum = (pick: (network: AdminNetworkHealth) => number): number =>
    props.networks.reduce((total, network) => total + pick(network), 0);

const toReconnect = (network: AdminNetworkHealth): number =>
    network.channels.token_expired + network.channels.disconnected;

const summary = computed((): AdminStat[] => {
    const published = sum((network) => network.last_24h.published);
    const failed = sum((network) => network.last_24h.failed);
    const total = published + failed;

    return [
        {
            key: 'connected',
            label: 'Connected channels',
            value: formatFullNumber(sum((network) => network.channels.connected)),
        },
        {
            key: 'reconnect',
            label: 'Need reconnecting',
            value: formatFullNumber(sum(toReconnect)),
        },
        {
            key: 'failed-24h',
            label: 'Failed in 24h',
            value: formatFullNumber(failed),
        },
        {
            key: 'rate-24h',
            label: 'Failure rate in 24h',
            value: formatPercent(percentOf(failed, total), 1),
        },
    ];
});

/** One row of the table, flat so every column can sort. */
type NetworkRow = {
    platform: string;
    label: string;
    connected: number;
    reconnect: number;
    published: number;
    failed: number;
    failure_rate: number;
    failure_rate_7d: number;
    last_failure_at: string;
};

const COLUMNS: AdminTableColumn[] = [
    { key: 'label', label: 'Network' },
    { key: 'connected', label: 'Connected', numeric: true },
    { key: 'reconnect', label: 'Need reconnecting', numeric: true },
    { key: 'published', label: 'Published 24h', numeric: true },
    { key: 'failed', label: 'Failed 24h', numeric: true },
    { key: 'failure_rate', label: 'Failure rate 24h', numeric: true },
    { key: 'failure_rate_7d', label: 'Failure rate 7d', numeric: true },
    { key: 'last_failure_at', label: 'Last failure' },
];

/** A network that never failed sorts first in the Last failure column. */
const lastFailureAt = (network: AdminNetworkHealth): string => {
    if (filled(network.last_failure_at)) {
        return network.last_failure_at;
    }

    return '';
};

const rows = computed((): NetworkRow[] =>
    props.networks.map((network) => ({
        platform: network.platform,
        label: network.label,
        connected: network.channels.connected,
        reconnect: toReconnect(network),
        published: network.last_24h.published,
        failed: network.last_24h.failed,
        failure_rate: network.last_24h.failure_rate,
        failure_rate_7d: network.last_7d.failure_rate,
        last_failure_at: lastFailureAt(network),
    })),
);

const rowKey = (row: NetworkRow): string => row.platform;

const rateVariant = (row: NetworkRow): BadgeVariant => {
    if (row.published + row.failed === 0) {
        return 'secondary';
    }

    if (row.failure_rate >= ALARMING_FAILURE_RATE) {
        return 'destructive';
    }

    if (row.failed > 0) {
        return 'warning';
    }

    return 'success';
};

/** A count of zero fades, so the numbers that matter stand out. */
const countClass = (count: number): string => {
    if (count === 0) {
        return 'text-sm text-muted-foreground/60';
    }

    return 'text-sm text-foreground';
};

const lastFailure = (row: NetworkRow): string => {
    if (filled(row.last_failure_at)) {
        return date.diffForHumans(row.last_failure_at);
    }

    return MISSING_VALUE;
};
</script>

<template>
    <Head title="Networks" />

    <AdminLayout
        title="Networks"
        description="How each network is doing: its channels and the posts sent there."
    >
        <Tabs
            :model-value="tab"
            class="flex flex-col gap-6"
            data-testid="admin-networks"
            @update:model-value="selectTab"
        >
            <TabsList variant="line">
                <TabsTrigger
                    v-for="option in TABS"
                    :key="option.value"
                    :value="option.value"
                    :data-testid="`admin-networks-tab-${option.value}`"
                >
                    {{ option.label }}
                </TabsTrigger>
            </TabsList>

            <TabsContent value="health" class="flex flex-col gap-6">
                <AnalyticsSection
                    title="Channels connected"
                    :subtitle="growthSubtitle"
                    flush
                >
                    <template #actions>
                        <AnalyticsRangePresets
                            :filters="filters"
                            :bounds="bounds"
                            :url="networksIndex.url()"
                            :range="range"
                        />
                    </template>
                    <AdminMetricTabs
                        v-model="growthView"
                        :tabs="growthViews"
                        test-id="admin-networks-growth"
                    />
                    <div class="border-t border-border px-4 py-4 sm:px-5">
                        <AdminDailyLineChart
                            v-if="isGrowthView('total')"
                            :days="growth.days"
                            :values="growth.total_by_day"
                            label="Channels"
                            :color="accountColor(0)"
                            test-id="admin-networks-total"
                        />
                        <AdminDailyBarChart
                            v-else
                            :days="growth.days"
                            :series="growthSeries"
                            show-total
                            test-id="admin-networks-growth"
                        />
                    </div>
                </AnalyticsSection>

                <AnalyticsSection title="Right now" flush>
                    <AdminStatGrid :stats="summary" />
                </AnalyticsSection>

                <AnalyticsSection
                    title="Networks"
                    subtitle="How the networks spread · Instagram and LinkedIn count every way they connect"
                    flush
                >
                    <AdminMetricTabs
                        v-model="distributionView"
                        :tabs="distributionViews"
                        test-id="admin-networks-distribution-tabs"
                    />
                    <AdminBarRows
                        :rows="distributionRows"
                        test-id="admin-networks-distribution"
                    />
                </AnalyticsSection>

                <AnalyticsSection
                    title="By network"
                    subtitle="Most channels first; click a column to sort. Failure rate = failed ÷ (published + failed), posts still on their way left out."
                    flush
                >
                    <AdminSortableTable
                        :columns="COLUMNS"
                        :rows="rows"
                        :row-key="rowKey"
                        default-sort="connected"
                        test-id="admin-networks-table"
                    >
                        <template #cell-label="{ row }">
                            <span
                                class="flex items-center gap-2.5"
                                :data-testid="`admin-network-${row.platform}`"
                            >
                                <PlatformLogo :platform="row.platform" size="xs" />
                                <span class="text-sm font-medium text-foreground">{{
                                    row.label
                                }}</span>
                            </span>
                        </template>
                        <template #cell-connected="{ row }">
                            <span class="text-sm font-medium text-foreground">{{
                                formatFullNumber(row.connected)
                            }}</span>
                        </template>
                        <template #cell-reconnect="{ row }">
                            <span :class="countClass(row.reconnect)">{{
                                formatFullNumber(row.reconnect)
                            }}</span>
                        </template>
                        <template #cell-published="{ row }">
                            <span :class="countClass(row.published)">{{
                                formatFullNumber(row.published)
                            }}</span>
                        </template>
                        <template #cell-failed="{ row }">
                            <span :class="countClass(row.failed)">{{
                                formatFullNumber(row.failed)
                            }}</span>
                        </template>
                        <template #cell-failure_rate="{ row }">
                            <Badge :variant="rateVariant(row)">{{
                                formatPercent(row.failure_rate, 1)
                            }}</Badge>
                        </template>
                        <template #cell-failure_rate_7d="{ row }">
                            <span class="text-sm text-muted-foreground">{{
                                formatPercent(row.failure_rate_7d, 1)
                            }}</span>
                        </template>
                        <template #cell-last_failure_at="{ row }">
                            <span class="text-sm whitespace-nowrap text-muted-foreground">{{
                                lastFailure(row)
                            }}</span>
                        </template>
                    </AdminSortableTable>
                </AnalyticsSection>
            </TabsContent>

            <TabsContent value="permissions" class="flex flex-col gap-4">
                <p class="text-sm text-muted-foreground">
                    What each network's connect asks the user to grant, and the features those
                    scopes turn on. Whether an app review approved a scope can't be read from the
                    networks' APIs.
                </p>
                <div
                    class="gap-4 lg:columns-2"
                    data-testid="admin-network-permissions"
                >
                    <NetworkPermissionsCard
                        v-for="network in permissions"
                        :key="network.platform"
                        :network="network"
                    />
                </div>
            </TabsContent>
        </Tabs>
    </AdminLayout>
</template>
