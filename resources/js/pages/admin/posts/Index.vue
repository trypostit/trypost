<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AdminSortableTable, {
    type AdminTableColumn,
} from '@/components/admin/AdminSortableTable.vue';
import AdminStatGrid, {
    type AdminStat,
} from '@/components/admin/AdminStatGrid.vue';
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
import { filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent, percentOf } from '@/lib/utils';
import { index as postsIndex } from '@/routes/admin/posts';
import { index as failedIndex } from '@/routes/admin/posts/failed';
import type { AdminPublishingStats } from '@/types/admin';
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
    { key: 'network', label: 'Network' },
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

/** A failure rate at or above this reads as trouble. */
const ALARMING_FAILURE_RATE = 10;

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

const originRows = computed(() =>
    props.stats.origins.map((origin) => ({
        ...origin,
        origin_label: originLabel(origin.origin),
        share: percentOf(origin.published + origin.failed, totalSent.value),
    })),
);

const typeRows = computed(() =>
    props.stats.content_types.map((type) => ({
        ...type,
        type: `${type.platform_label} · ${type.label}`,
    })),
);

const typeKey = (row: { platform: string; content_type: string }): string =>
    `${row.platform}-${row.content_type}`;

const maxCategory = computed(() =>
    Math.max(1, ...props.stats.categories.map((category) => category.count)),
);

const shareWidth = (share: number): string => `${share}%`;

const categoryWidth = (count: number): string => `${(count / maxCategory.value) * 100}%`;

const failedUrl = (query: Record<string, string | string[]>): string =>
    failedIndex.url({ query });

const categoryUrl = (category: string): string =>
    failedUrl({ ...networkQuery.value, category });

const errorUrl = (row: { network: string; category: string }): string =>
    failedUrl({ networks: [row.network], category: row.category });

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
        network: getPlatformLabel(error.platform),
    })),
);

const networkKey = (row: { platform: string }): string => row.platform;
const errorKey = (row: { platform: string; message: string }): string =>
    `${row.platform}-${row.message}`;

const hasOrigins = computed(() => filled(props.stats.origins));
const hasNetworks = computed(() => filled(props.stats.networks));
const hasErrors = computed(() => filled(props.stats.top_errors));
const hasPosts = computed(() => filled(props.stats.networks));
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
                <PublishingBarChart v-if="hasPosts" :days="stats.days" />
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
                <p
                    v-if="!hasNetworks"
                    class="mx-4 mb-4 rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground sm:mx-5 sm:mb-5"
                >
                    No posts in this period.
                </p>
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
                    <p
                        v-if="!hasCategories"
                        class="mx-4 mb-4 rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground sm:mx-5 sm:mb-5"
                    >
                        No failures in this period.
                    </p>
                    <ul
                        v-else
                        class="flex flex-col border-t border-border py-2"
                        data-testid="admin-publishing-categories"
                    >
                        <li v-for="category in stats.categories" :key="category.category">
                            <Link
                                :href="categoryUrl(category.category)"
                                class="flex items-center gap-3 px-4 py-1.5 transition-colors hover:bg-muted/60 sm:px-5"
                            >
                                <span class="w-32 shrink-0 truncate text-sm text-foreground">{{
                                    errorCategoryLabel(category.category)
                                }}</span>
                                <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                    <span
                                        class="block h-full rounded-full bg-destructive/70"
                                        :style="{ width: categoryWidth(category.count) }"
                                    />
                                </span>
                                <span
                                    class="w-12 shrink-0 text-right text-sm font-medium text-foreground tabular-nums"
                                    >{{ formatFullNumber(category.count) }}</span
                                >
                            </Link>
                        </li>
                    </ul>
                </AnalyticsSection>

                <AnalyticsSection
                    title="By origin"
                    subtitle="Where the posts were made: share of posts, published, failure rate"
                    flush
                >
                    <p
                        v-if="!hasOrigins"
                        class="mx-4 mb-4 rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground sm:mx-5 sm:mb-5"
                    >
                        No posts in this period.
                    </p>
                    <ul
                        v-else
                        class="flex flex-col border-t border-border py-2"
                        data-testid="admin-publishing-origins"
                    >
                        <li
                            v-for="origin in originRows"
                            :key="origin.origin"
                            class="flex items-center gap-3 px-4 py-1.5 sm:px-5"
                        >
                            <span class="w-28 shrink-0 truncate text-sm text-foreground">{{
                                origin.origin_label
                            }}</span>
                            <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <span
                                    class="block h-full rounded-full bg-primary-text"
                                    :style="{ width: shareWidth(origin.share) }"
                                />
                            </span>
                            <span
                                class="w-16 shrink-0 text-right text-sm font-medium text-foreground tabular-nums"
                                >{{ formatFullNumber(origin.published) }}</span
                            >
                            <span
                                class="w-12 shrink-0 text-right text-xs tabular-nums"
                                :class="rateClass(origin.failure_rate)"
                                title="Failure rate"
                                >{{ formatPercent(origin.failure_rate, 1) }}</span
                            >
                        </li>
                    </ul>
                </AnalyticsSection>
            </div>

            <AnalyticsSection
                title="By post type"
                :subtitle="rangeSubtitle"
                flush
            >
                <p
                    v-if="!hasTypes"
                    class="mx-4 mb-4 rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground sm:mx-5 sm:mb-5"
                >
                    No posts in this period.
                </p>
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
                <p
                    v-if="!hasErrors"
                    class="mx-4 mb-4 rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground sm:mx-5 sm:mb-5"
                >
                    No failures in this period.
                </p>
                <AdminSortableTable
                    v-else
                    :columns="ERROR_COLUMNS"
                    :rows="errorRows"
                    :row-key="errorKey"
                    default-sort="count"
                    test-id="admin-publishing-errors"
                >
                    <template #cell-network="{ row }">
                        <span class="flex items-center gap-2.5">
                            <PlatformLogo :platform="row.platform" size="xs" />
                            <span class="text-sm font-medium whitespace-nowrap text-foreground">{{
                                row.network
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
