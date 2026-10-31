<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    IconChartBar,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconDotsVertical,
    IconExternalLink,
    IconLayoutColumns,
    IconLink,
    IconPhoto,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import PublicationTypeBadge from '@/components/analytics/PublicationTypeBadge.vue';
import AnalyticsModeToggle from '@/components/analytics/workspace/AnalyticsModeToggle.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PostDetailsDialog from '@/components/publish/PostDetailsDialog.vue';
import PublicationDetailsDialog from '@/components/publish/PublicationDetailsDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { usePostDetails } from '@/composables/usePostDetails';
import date from '@/date';
import { isVideo } from '@/lib/mediaType';
import {
    copyToClipboard,
    formatNumberCompact,
    formatPercent,
} from '@/lib/utils';
import { videoFrameUrl } from '@/lib/videoFrame';
import type {
    AnalyticsReport,
    ChannelInsightsFilters,
    ChannelPublicationPage,
    ChannelPublicationRow,
    PublicationPeriod,
    SummaryMetric,
} from '@/types/analytics';

const props = defineProps<{
    report: AnalyticsReport;
    filters: ChannelInsightsFilters;
    publications: ChannelPublicationPage | undefined;
    availableMetrics: SummaryMetric[];
    sortableMetrics: SummaryMetric[];
    url: string;
    platform: string;
}>();

const TABLE_METRICS: SummaryMetric[] = [
    'reactions',
    'comments',
    'engagement_rate',
    'views',
    'impressions',
    'shares',
    'reposts',
    'quotes',
    'saves',
    'clicks',
    'follows_gained',
    'reach',
    'watch_time_minutes',
    'average_watch_time_seconds',
];
const storageKey = computed(() => `insights.columns.${props.platform}`);

const readStoredColumns = (): SummaryMetric[] | null => {
    try {
        const stored = JSON.parse(
            window.localStorage.getItem(storageKey.value) ?? 'null',
        );

        return Array.isArray(stored) ? (stored as SummaryMetric[]) : null;
    } catch {
        return null;
    }
};

const storedColumns = ref<SummaryMetric[] | null>(readStoredColumns());
const choosableColumns = computed<SummaryMetric[]>(() =>
    TABLE_METRICS.filter((metric) => props.availableMetrics.includes(metric)),
);
const defaultColumns = computed<SummaryMetric[]>(() =>
    choosableColumns.value.filter((metric) =>
        props.sortableMetrics.includes(metric),
    ),
);
const columns = computed<SummaryMetric[]>(() => {
    const chosen = storedColumns.value;
    const picked = chosen
        ? choosableColumns.value.filter((metric) => chosen.includes(metric))
        : [];

    const shown = picked.length ? picked : defaultColumns.value;

    return choosableColumns.value.filter(
        (metric) => shown.includes(metric) || metric === props.filters.sort,
    );
});

watch(storageKey, () => {
    storedColumns.value = readStoredColumns();
});
const isSortable = (metric: SummaryMetric): boolean =>
    props.sortableMetrics.includes(metric);

const toggleColumn = (
    metric: SummaryMetric,
    visible: boolean | 'indeterminate',
): void => {
    const next = choosableColumns.value.filter((candidate) =>
        candidate === metric
            ? visible === true
            : columns.value.includes(candidate),
    );

    if (next.length === 0) {
        return;
    }

    storedColumns.value = next;

    try {
        window.localStorage.setItem(storageKey.value, JSON.stringify(next));
    } catch {
        return;
    }
};

const keepMenuOpen = (event: Event): void => {
    event.preventDefault();
};
const periods = [
    {
        mode: 'current',
        label: 'analytics.channel.this_period',
        test: 'insights-period-current',
    },
    {
        mode: 'previous',
        label: 'analytics.channel.previous_period',
        test: 'insights-period-previous',
    },
] as const;
const periodRange = computed(() =>
    props.filters.period === 'previous'
        ? props.report.previous_range
        : props.report.range,
);
const postCount = computed(
    () =>
        (props.filters.period === 'previous'
            ? props.report.summary.posts.previous
            : props.report.summary.posts.value) ?? 0,
);

const rows = computed<ChannelPublicationRow[]>(
    () => props.publications?.data ?? [],
);
const currentPage = computed(() => props.publications?.current_page ?? 1);
const lastPage = computed(() => props.publications?.last_page ?? 1);

const GAP = 'gap' as const;

type PageItem = number | typeof GAP;

const pages = computed<PageItem[]>(() => {
    const last = lastPage.value;
    const current = currentPage.value;

    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const around = [current - 1, current, current + 1].filter(
        (page) => page > 1 && page < last,
    );
    const items: PageItem[] = [1];

    if (around[0] > 2) {
        items.push(GAP);
    }

    items.push(...around);

    if (around[around.length - 1] < last - 1) {
        items.push(GAP);
    }

    items.push(last);

    return items;
});


const isReloading = ref(false);

const reload = (
    changes: Partial<{
        period: PublicationPeriod;
        sort: SummaryMetric;
        page: number;
    }>,
): void => {
    const { range, start, end, period, sort, labels, untagged, types } =
        props.filters;

    router.get(
        props.url,
        {
            range,
            ...(range === 'custom' ? { start, end } : {}),
            period,
            sort,
            labels,
            types,
            ...(untagged ? { untagged: '1' } : {}),
            ...changes,
        },
        {
            only: ['publications', 'filters'],
            preserveState: true,
            preserveScroll: true,
            onStart: () => {
                isReloading.value = true;
            },
            onFinish: () => {
                isReloading.value = false;
            },
            replace: true,
        },
    );
};

const changePeriod = (period: string): void => {
    if (period !== props.filters.period) {
        reload({ period: period as PublicationPeriod });
    }
};

const changeSort = (sort: SummaryMetric): void => {
    if (sort !== props.filters.sort) {
        reload({ sort });
    }
};

const goToPage = (page: number): void => {
    if (page >= 1 && page <= lastPage.value && page !== currentPage.value) {
        reload({ page });
    }
};

const goToPreviousPage = (): void => {
    goToPage(currentPage.value - 1);
};

const goToNextPage = (): void => {
    goToPage(currentPage.value + 1);
};

const copyLink = (row: ChannelPublicationRow): void => {
    if (row.permalink) {
        copyToClipboard(row.permalink, undefined, { showSuccessToast: false });
    }
};

const {
    detailsPost,
    detailsOpen,
    openPost,
    detailsPublication,
    publicationOpen,
    openPublication,
    editPost,
    runPostAction,
} = usePostDetails();

const openRow = (row: ChannelPublicationRow): void => {
    if (row.post_id) {
        openPost(row.post_id);

        return;
    }

    openPublication(row.id);
};


const display = (row: ChannelPublicationRow, key: SummaryMetric): string => {
    const value = row.metrics[key];

    if (value === null || value === undefined) {
        return '—';
    }

    return key === 'engagement_rate'
        ? formatPercent(value)
        : formatNumberCompact(value);
};
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.channel.performance')"
        :info="$t('analytics.insights.about.channel_posts')"
        info-testid="insights-posts-about"
        :range="periodRange"
        flush
    >
        <template #actions>
            <AnalyticsModeToggle
                :model-value="filters.period"
                label="analytics.channel.performance"
                :options="periods"
                @update:model-value="changePeriod"
            />
        </template>

        <div
            class="min-w-0 border-t border-border transition-opacity"
            :class="{ 'opacity-60': isReloading }"
            :aria-busy="isReloading"
        >
            <div
                v-if="rows.length === 0"
                class="px-5 py-10 text-center text-sm text-muted-foreground"
                data-testid="insights-posts-empty"
            >
                {{ $t('analytics.channel.no_posts') }}
            </div>
            <template v-else>
                <div class="overflow-x-auto">
                    <Table class="min-w-[720px]" data-testid="insights-posts">
                        <TableHeader>
                            <TableRow
                                class="border-border bg-muted/60 hover:bg-muted/60"
                            >
                                <TableHead
                                    class="h-10 border-r-0 px-4 py-2 text-xs font-medium text-muted-foreground sm:ps-5"
                                >
                                    {{
                                        $t('analytics.channel.posts_count', {
                                            count: formatNumberCompact(postCount),
                                        })
                                    }}
                                </TableHead>
                                <TableHead
                                    v-for="column in columns"
                                    :key="column"
                                    class="h-10 border-r-0 px-4 py-2 text-xs font-medium text-muted-foreground"
                                    :aria-sort="
                                        filters.sort === column
                                            ? 'descending'
                                            : 'none'
                                    "
                                >
                                    <span
                                        v-if="!isSortable(column)"
                                        class="whitespace-nowrap"
                                        :data-testid="`insights-column-head-${column}`"
                                        >{{
                                            $t(
                                                `analytics.channel.metrics.${column}.label`,
                                            )
                                        }}</span
                                    >
                                    <button
                                        v-else
                                        type="button"
                                        class="group inline-flex cursor-pointer items-center gap-1 rounded-sm whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        :data-testid="`insights-sort-${column}`"
                                        @click="changeSort(column)"
                                    >
                                        {{
                                            $t(
                                                `analytics.channel.metrics.${column}.label`,
                                            )
                                        }}
                                        <IconChevronDown
                                            :class="[
                                                'size-3 transition-opacity',
                                                filters.sort === column
                                                    ? 'opacity-100'
                                                    : 'opacity-30 group-hover:opacity-60',
                                            ]"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </TableHead>
                                <TableHead
                                    class="sticky right-0 h-10 w-12 border-r-0 bg-muted px-2 py-1 text-right sm:pe-3"
                                >
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <button
                                                type="button"
                                                class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                                :aria-label="
                                                    $t(
                                                        'analytics.channel.columns',
                                                    )
                                                "
                                                data-testid="insights-columns-trigger"
                                            >
                                                <IconLayoutColumns
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-60"
                                            data-testid="insights-columns-menu"
                                        >
                                            <DropdownMenuLabel>{{
                                                $t('analytics.channel.columns')
                                            }}</DropdownMenuLabel>
                                            <DropdownMenuCheckboxItem
                                                v-for="metric in choosableColumns"
                                                :key="metric"
                                                :model-value="
                                                    columns.includes(metric)
                                                "
                                                :disabled="
                                                    metric === filters.sort ||
                                                    (columns.length === 1 &&
                                                        columns.includes(metric))
                                                "
                                                :data-testid="`insights-column-${metric}`"
                                                @select="keepMenuOpen"
                                                @update:model-value="
                                                    toggleColumn(metric, $event)
                                                "
                                            >
                                                {{
                                                    $t(
                                                        `analytics.channel.metrics.${metric}.label`,
                                                    )
                                                }}
                                            </DropdownMenuCheckboxItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody id="insights-posts-body">
                            <TableRow
                                v-for="row in rows"
                                :key="row.id"
                                class="border-border last:border-b-0"
                                :data-testid="`insights-posts-row-${row.id}`"
                            >
                                <TableCell class="border-r-0 px-4 py-3 sm:ps-5">
                                    <button
                                        type="button"
                                        class="flex max-w-md min-w-0 items-center gap-3 rounded-md text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        :data-testid="`insights-posts-link-${row.id}`"
                                        @click="openRow(row)"
                                    >
                                        <span
                                            class="inline-flex h-6 min-w-7 shrink-0 items-center justify-center rounded-md bg-accent px-1.5 text-xs font-medium text-foreground tabular-nums"
                                            :data-testid="`insights-posts-rank-${row.id}`"
                                            >#{{ row.rank }}</span
                                        >
                                        <span
                                            class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-muted-foreground"
                                        >
                                            <video
                                                v-if="
                                                    row.media &&
                                                    isVideo(row.media)
                                                "
                                                :src="videoFrameUrl(row.media)"
                                                class="size-full object-cover"
                                                muted
                                                playsinline
                                                preload="metadata"
                                                aria-hidden="true"
                                                :data-testid="`insights-posts-video-${row.id}`"
                                            />
                                            <img
                                                v-else-if="row.thumbnail_url"
                                                :src="row.thumbnail_url"
                                                alt=""
                                                class="size-full object-cover"
                                                loading="lazy"
                                            />
                                            <IconPhoto
                                                v-else
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <span
                                            class="flex min-w-0 flex-col gap-0.5 whitespace-normal"
                                        >
                                            <span
                                                class="line-clamp-2 text-sm"
                                                :class="
                                                    row.excerpt
                                                        ? 'text-foreground'
                                                        : 'text-muted-foreground'
                                                "
                                            >
                                                {{
                                                    row.excerpt ||
                                                    $t(
                                                        'analytics.dashboard.no_excerpt',
                                                    )
                                                }}
                                            </span>
                                            <span
                                                class="flex items-center gap-2 text-xs text-muted-foreground"
                                            >
                                                {{
                                                    date.formatDateShort(
                                                        row.published_at,
                                                    )
                                                }}
                                                <PublicationTypeBadge
                                                    :platform="platform"
                                                    :content-type="row.content_type"
                                                    :test-id="`insights-posts-type-${row.id}`"
                                                />
                                            </span>
                                        </span>
                                    </button>
                                </TableCell>
                                <TableCell
                                    v-for="column in columns"
                                    :key="column"
                                    class="border-r-0 px-4 py-3 text-foreground tabular-nums"
                                >
                                    {{ display(row, column) }}
                                </TableCell>
                                <TableCell
                                    class="border-r-0 px-2 py-3 text-right sm:pe-3"
                                >
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                class="text-muted-foreground"
                                                :aria-label="
                                                    $t(
                                                        'analytics.channel.row.actions',
                                                    )
                                                "
                                                :data-testid="`insights-posts-actions-${row.id}`"
                                            >
                                                <IconDotsVertical
                                                    aria-hidden="true"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-52"
                                        >
                                            <DropdownMenuItem
                                                :data-testid="`insights-posts-details-${row.id}`"
                                                @select="openRow(row)"
                                            >
                                                <IconChartBar
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    $t(
                                                        'analytics.channel.row.details',
                                                    )
                                                }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="row.permalink"
                                                as-child
                                            >
                                                <a
                                                    :href="row.permalink"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    :data-testid="`insights-posts-open-${row.id}`"
                                                >
                                                    <IconExternalLink
                                                        aria-hidden="true"
                                                    />
                                                    {{
                                                        $t(
                                                            'analytics.channel.row.view_post',
                                                        )
                                                    }}
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="row.permalink"
                                                :data-testid="`insights-posts-copy-${row.id}`"
                                                @select="copyLink(row)"
                                            >
                                                <IconLink aria-hidden="true" />
                                                {{
                                                    $t(
                                                        'analytics.channel.row.copy_link',
                                                    )
                                                }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </template>
        </div>
        <nav
            v-if="lastPage > 1"
            class="flex items-center justify-center gap-1 border-t border-border px-4 py-3"
            :aria-label="$t('analytics.channel.pagination.label')"
            data-testid="insights-posts-pagination"
        >
            <Button
                variant="ghost"
                size="icon-xs"
                class="text-muted-foreground"
                :disabled="currentPage <= 1"
                :aria-label="$t('analytics.channel.pagination.previous')"
                data-testid="insights-posts-page-previous"
                @click="goToPreviousPage"
            >
                <IconChevronLeft aria-hidden="true" />
            </Button>
            <template v-for="(page, index) in pages" :key="`${page}-${index}`">
                <span
                    v-if="page === GAP"
                    class="inline-flex h-6 min-w-6 items-center justify-center text-sm text-muted-foreground"
                    aria-hidden="true"
                    >…</span
                >
                <Button
                    v-else
                    variant="ghost"
                    size="icon-xs"
                    class="w-auto min-w-6 px-1.5 tabular-nums"
                    :class="
                        page === currentPage
                            ? 'bg-primary-selected text-primary-text hover:bg-primary-selected hover:text-primary-text'
                            : 'text-muted-foreground'
                    "
                    :aria-current="page === currentPage ? 'page' : undefined"
                    :aria-label="
                        $t('analytics.channel.pagination.page', {
                            page: String(page),
                        })
                    "
                    :data-testid="`insights-posts-page-${page}`"
                    @click="goToPage(page)"
                >
                    {{ page }}
                </Button>
            </template>
            <Button
                variant="ghost"
                size="icon-xs"
                class="text-muted-foreground"
                :disabled="currentPage >= lastPage"
                :aria-label="$t('analytics.channel.pagination.next')"
                data-testid="insights-posts-page-next"
                @click="goToNextPage"
            >
                <IconChevronRight aria-hidden="true" />
            </Button>
        </nav>
        <PublicationDetailsDialog
            v-if="detailsPublication"
            v-model:open="publicationOpen"
            :detail="detailsPublication"
            :channel-id="null"
        />
        <PostDetailsDialog
            v-if="detailsPost"
            v-model:open="detailsOpen"
            :post="detailsPost"
            :test-key="detailsPost.id"
            :timezone="date.getUserTimezone()"
            @select="runPostAction"
            @edit="editPost"
        />
    </AnalyticsSection>
</template>
