<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AdminBarRows from '@/components/admin/AdminBarRows.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import AdminSortableTable from '@/components/admin/AdminSortableTable.vue';
import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import PublishingBarChart from '@/components/admin/PublishingBarChart.vue';
import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import NetworkFilter from '@/components/NetworkFilter.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Badge } from '@/components/ui/badge';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { errorCategoryLabel } from '@/lib/adminErrorCategories';
import { ALARMING_FAILURE_RATE } from '@/lib/adminPublishing';
import { filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent, percentOf } from '@/lib/utils';
import { index as postsIndex } from '@/routes/admin/posts';
import { index as failedIndex } from '@/routes/admin/posts/failed';
import type {
    AdminBarRow,
    AdminPublishingStats,
    AdminStat,
    AdminTableColumn,
} from '@/types/admin';
import type { AnalyticsFilters, DateRange } from '@/types/analytics';

const props = defineProps<{
    stats: AdminPublishingStats;
    networks: string[];
    range: DateRange;
    bounds: { min: string | null; max: string | null };
    filters: AnalyticsFilters & { networks: string[] };
}>();

const selectedNetworks = ref<string[]>([...props.filters.networks]);

/** The range as the URL carries it: a preset, or custom with its days. */
const rangeQuery = (): Record<string, string> => {
    if (props.filters.range === 'custom') {
        return {
            range: 'custom',
            start: props.filters.start,
            end: props.filters.end,
        };
    }

    return { range: props.filters.range };
};

const networkQuery = computed((): Record<string, string[]> => ({
    networks: selectedNetworks.value,
}));

watch(
    selectedNetworks,
    () => {
        router.get(
            postsIndex.url(),
            { ...rangeQuery(), ...networkQuery.value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
    { deep: true },
);

const summary = computed((): AdminStat[] => [
    {
        key: 'published',
        label: 'Published',
        value: formatFullNumber(props.stats.totals.published),
    },
    {
        key: 'failed',
        label: 'Failed',
        value: formatFullNumber(props.stats.totals.failed),
    },
    {
        key: 'failure-rate',
        label: 'Failure rate',
        value: formatPercent(props.stats.totals.failure_rate, 1),
    },
]);

const rangeSubtitle = computed(
    (): string => date.formatDayMonthYearRange(props.range),
);

const NETWORK_COLUMNS: AdminTableColumn[] = [
    { key: 'network', label: 'Network' },
    { key: 'published', label: 'Published', numeric: true },
    { key: 'failed', label: 'Failed', numeric: true },
    { key: 'failure_rate', label: 'Failure rate', numeric: true },
];

const ERROR_COLUMNS: AdminTableColumn[] = [
    { key: 'network_label', label: 'Network' },
    { key: 'message', label: 'Error' },
    { key: 'count', label: 'Posts', numeric: true },
];

const TYPE_COLUMNS: AdminTableColumn[] = [
    { key: 'type', label: 'Post type' },
    { key: 'published', label: 'Published', numeric: true },
    { key: 'failed', label: 'Failed', numeric: true },
    { key: 'failure_rate', label: 'Failure rate', numeric: true },
];

const ORIGIN_LABELS: Record<string, string> = {
    web: 'Web app',
    api: 'REST API',
    mcp: 'AI apps (MCP)',
    repurpose: 'Repurpose',
};

const rateClass = (rate: number): string => {
    if (rate >= ALARMING_FAILURE_RATE) {
        return 'text-destructive';
    }

    return 'text-foreground';
};

const originLabel = (origin: string): string => {
    const label = ORIGIN_LABELS[origin];

    if (filled(label)) {
        return label;
    }

    return origin;
};

const totalSent = computed(() =>
    props.stats.origins.reduce((sum, origin) => sum + origin.published + origin.failed, 0),
);

/** Each origin's bar is its share of the posts sent; it shows what it published and its failure rate. */
const originRows = computed((): AdminBarRow[] =>
    props.stats.origins.map((origin) => ({
        key: origin.origin,
        label: originLabel(origin.origin),
        count: origin.published,
        bar: percentOf(origin.published + origin.failed, totalSent.value),
    })),
);

const originFailureRate = (row: AdminBarRow): number => {
    const origin = props.stats.origins.find((candidate) => candidate.origin === row.key);

    if (filled(origin)) {
        return origin.failure_rate;
    }

    return 0;
};

const typeRows = computed(() =>
    props.stats.content_types.map((type) => ({
        ...type,
        type: `${type.platform_label} · ${type.label}`,
    })),
);

const typeKey = (row: { platform: string; content_type: string }): string =>
    `${row.platform}-${row.content_type}`;

const failedUrl = (query: Record<string, string | string[]>): string =>
    failedIndex.url({ query });

const categoryUrl = (category: string): string =>
    failedUrl({ ...networkQuery.value, category });

const errorUrl = (row: { network: string; category: string }): string =>
    failedUrl({ networks: [row.network], category: row.category });

const categoryRows = computed((): AdminBarRow[] =>
    props.stats.categories.map((category) => ({
        key: category.category,
        label: errorCategoryLabel(category.category),
        count: category.count,
        href: categoryUrl(category.category),
        barClass: 'bg-destructive/70',
    })),
);

const hasCategories = computed(() => filled(props.stats.categories));
const hasTypes = computed(() => filled(props.stats.content_types));

const networkRows = computed(() =>
    props.stats.networks.map((network) => ({
        ...network,
        network: getPlatformLabel(network.platform),
    })),
);

const errorRows = computed(() =>
    props.stats.top_errors.map((error) => ({
        ...error,
        network_label: getPlatformLabel(error.platform),
    })),
);

const networkKey = (row: { platform: string }): string => row.platform;
const errorKey = (row: { platform: string; message: string }): string =>
    `${row.platform}-${row.message}`;

const hasOrigins = computed(() => filled(props.stats.origins));
/** A network row exists only for a network that sent posts, so it also tells whether anything was sent. */
const hasNetworks = computed(() => filled(props.stats.networks));
const hasErrors = computed(() => filled(props.stats.top_errors));
</script>

<template>
    <Head title="Publishing" />

    <AdminLayout
        title="Publishing"
        description="What TryPost sent to the networks, across every workspace."
    >
        <div class="flex flex-col gap-6" data-testid="admin-publishing">
            <div
                class="flex min-w-0 flex-wrap items-center justify-between gap-2 border-b border-border-strong pb-3"
                data-testid="admin-publishing-toolbar"
            >
                <AnalyticsRangePresets
                    :filters="filters"
                    :bounds="bounds"
                    :url="postsIndex.url()"
                    :range="range"
                    :keep="networkQuery"
                />
                <NetworkFilter
                    v-model="selectedNetworks"
                    :networks="networks"
                    test-id="admin-posts-network"
                    sheet-below-sm
                />
            </div>
            <AnalyticsSection title="Summary" :subtitle="rangeSubtitle" flush>
                <AdminStatGrid :stats="summary" />
            </AnalyticsSection>

            <AnalyticsSection title="Posts per day" :subtitle="rangeSubtitle">
                <PublishingBarChart v-if="hasNetworks" :days="stats.days" />
                <p
                    v-else
                    class="py-16 text-center text-sm text-muted-foreground"
                    data-testid="admin-publishing-empty"
                >
                    Nothing was published in this period.
                </p>
            </AnalyticsSection>

            <AnalyticsSection
                title="By network"
                :subtitle="rangeSubtitle"
                flush
            >
                <AdminEmptyState v-if="!hasNetworks" variant="section">No posts in this period.</AdminEmptyState>
                <AdminSortableTable
                    v-else
                    :columns="NETWORK_COLUMNS"
                    :rows="networkRows"
                    :row-key="networkKey"
                    default-sort="published"
                    test-id="admin-publishing-networks"
                >
                    <template #cell-network="{ row }">
                        <span class="flex items-center gap-2.5">
                            <PlatformLogo :platform="row.platform" size="xs" />
                            <span class="text-sm font-medium text-foreground">{{
                                row.network
                            }}</span>
                        </span>
                    </template>
                    <template #cell-published="{ row }">
                        <span class="text-sm font-medium text-foreground">{{
                            formatFullNumber(row.published)
                        }}</span>
                    </template>
                    <template #cell-failed="{ row }">
                        <span class="text-sm font-medium text-foreground">{{
                            formatFullNumber(row.failed)
                        }}</span>
                    </template>
                    <template #cell-failure_rate="{ row }">
                        <span class="text-sm font-medium" :class="rateClass(row.failure_rate)">{{
                            formatPercent(row.failure_rate, 1)
                        }}</span>
                    </template>
                </AdminSortableTable>
            </AnalyticsSection>

            <div class="grid gap-6 lg:grid-cols-2">
                <AnalyticsSection
                    title="Failures by cause"
                    subtitle="Click a cause to see its posts"
                    flush
                >
                    <AdminEmptyState v-if="!hasCategories" variant="section">No failures in this period.</AdminEmptyState>
                    <AdminBarRows
                        v-else
                        :rows="categoryRows"
                        label-class="w-32"
                        test-id="admin-publishing-categories"
                    />
                </AnalyticsSection>

                <AnalyticsSection
                    title="By origin"
                    subtitle="Where the posts were made: share of posts, published, failure rate"
                    flush
                >
                    <AdminEmptyState v-if="!hasOrigins" variant="section">No posts in this period.</AdminEmptyState>
                    <AdminBarRows
                        v-else
                        :rows="originRows"
                        label-class="w-28"
                        test-id="admin-publishing-origins"
                    >
                        <template #value="{ row }">
                            <span
                                class="w-16 shrink-0 text-right text-sm font-medium text-foreground tabular-nums"
                                >{{ formatFullNumber(row.count) }}</span
                            >
                            <span
                                class="w-12 shrink-0 text-right text-xs tabular-nums"
                                :class="rateClass(originFailureRate(row))"
                                title="Failure rate"
                                >{{ formatPercent(originFailureRate(row), 1) }}</span
                            >
                        </template>
                    </AdminBarRows>
                </AnalyticsSection>
            </div>

            <AnalyticsSection
                title="By post type"
                :subtitle="rangeSubtitle"
                flush
            >
                <AdminEmptyState v-if="!hasTypes" variant="section">No posts in this period.</AdminEmptyState>
                <AdminSortableTable
                    v-else
                    :columns="TYPE_COLUMNS"
                    :rows="typeRows"
                    :row-key="typeKey"
                    default-sort="published"
                    test-id="admin-publishing-types"
                >
                    <template #cell-type="{ row }">
                        <span class="flex items-center gap-2.5">
                            <PlatformLogo :platform="row.platform" size="xs" />
                            <span class="text-sm font-medium text-foreground">{{ row.type }}</span>
                        </span>
                    </template>
                    <template #cell-published="{ row }">
                        <span class="text-sm font-medium text-foreground">{{
                            formatFullNumber(row.published)
                        }}</span>
                    </template>
                    <template #cell-failed="{ row }">
                        <span class="text-sm font-medium text-foreground">{{
                            formatFullNumber(row.failed)
                        }}</span>
                    </template>
                    <template #cell-failure_rate="{ row }">
                        <span class="text-sm font-medium" :class="rateClass(row.failure_rate)">{{
                            formatPercent(row.failure_rate, 1)
                        }}</span>
                    </template>
                </AdminSortableTable>
            </AnalyticsSection>

            <AnalyticsSection
                title="Most frequent errors"
                :subtitle="rangeSubtitle"
                flush
            >
                <AdminEmptyState v-if="!hasErrors" variant="section">No failures in this period.</AdminEmptyState>
                <AdminSortableTable
                    v-else
                    :columns="ERROR_COLUMNS"
                    :rows="errorRows"
                    :row-key="errorKey"
                    default-sort="count"
                    test-id="admin-publishing-errors"
                >
                    <template #cell-network_label="{ row }">
                        <span class="flex items-center gap-2.5">
                            <PlatformLogo :platform="row.platform" size="xs" />
                            <span class="text-sm font-medium whitespace-nowrap text-foreground">{{
                                row.network_label
                            }}</span>
                        </span>
                    </template>
                    <template #cell-message="{ row }">
                        <Link
                            :href="errorUrl(row)"
                            class="group flex flex-col gap-1"
                        >
                            <Badge variant="destructive-subtle" class="w-fit">{{
                                errorCategoryLabel(row.category)
                            }}</Badge>
                            <span
                                class="line-clamp-2 text-sm text-foreground group-hover:underline"
                                >{{ row.message }}</span
                            >
                        </Link>
                    </template>
                    <template #cell-count="{ row }">
                        <span class="text-sm font-medium text-foreground">{{
                            formatFullNumber(row.count)
                        }}</span>
                    </template>
                </AdminSortableTable>
            </AnalyticsSection>
        </div>
    </AdminLayout>
</template>
