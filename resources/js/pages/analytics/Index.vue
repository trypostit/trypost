<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { IconChartBar } from '@tabler/icons-vue';
import { computed, onMounted, ref, watch } from 'vue';

import FollowersChart from '@/components/analytics/workspace/FollowersChart.vue';
import ImportCoverage from '@/components/analytics/workspace/ImportCoverage.vue';
import PerformanceTable from '@/components/analytics/workspace/PerformanceTable.vue';
import PostsChart from '@/components/analytics/workspace/PostsChart.vue';
import SummaryCards from '@/components/analytics/workspace/SummaryCards.vue';
import TopPosts from '@/components/analytics/workspace/TopPosts.vue';
import {
    accountColor,
    type WorkspaceAnalyticsReport,
} from '@/components/analytics/workspace/types';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import dayjs from '@/dayjs';
import AppLayout from '@/layouts/AppLayout.vue';
import { analytics as analyticsRoute } from '@/routes/app';

const props = defineProps<{ report: WorkspaceAnalyticsReport }>();
const importRunning = computed(() =>
    props.report.coverage.some(
        (row) =>
            row.collector === 'publication_backfill' &&
            (row.status === 'pending' || row.status === 'running'),
    ),
);
const { start: startImportPolling, stop: stopImportPolling } = usePoll(
    5000,
    { only: ['report'] },
    { autoStart: false },
);
const { start: startIdlePolling, stop: stopIdlePolling } = usePoll(
    15000,
    { only: ['report'] },
    { autoStart: false },
);

const syncPolling = (running: boolean): void => {
    if (running) {
        stopIdlePolling();
        startImportPolling();
    } else {
        stopImportPolling();
        startIdlePolling();
    }
};

onMounted(() => syncPolling(importRunning.value));
watch(importRunning, syncPolling);

const accountColors = computed<Record<string, string>>(() => {
    const keys = [
        ...new Set(
            [
                ...props.report.followers.accounts,
                ...props.report.posts.accounts,
            ].map((account) => account.social_account_key),
        ),
    ];

    return Object.fromEntries(
        keys.map((key, index) => [key, accountColor(index)]),
    );
});
const selectedRange = ref({
    start: dayjs(props.report.range.start).toDate(),
    end: dayjs(props.report.range.end).toDate(),
});

watch(
    () => [props.report.range.start, props.report.range.end],
    ([start, end]) => {
        selectedRange.value = {
            start: dayjs(start).toDate(),
            end: dayjs(end).toDate(),
        };
    },
);

const changeRange = (range: { start: Date; end: Date }): void => {
    selectedRange.value = range;
    const start = dayjs(range.start).format('YYYY-MM-DD');
    const end = dayjs(range.end).format('YYYY-MM-DD');
    if (start === props.report.range.start && end === props.report.range.end)
        return;

    router.get(
        analyticsRoute.url(),
        { start, end },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('analytics.title')" />
        <div class="flex min-h-full shrink-0 flex-col gap-8 px-6 py-8">
            <header
                class="flex flex-wrap items-end justify-between gap-4"
                data-testid="analytics-page-header"
            >
                <PageHeader
                    :title="$t('analytics.title')"
                    :description="
                        $t('analytics.dashboard.workspace_description')
                    "
                />
                <DateRangePicker
                    :model-value="selectedRange"
                    :min-date="
                        report.bounds.min
                            ? dayjs(report.bounds.min).toDate()
                            : undefined
                    "
                    :max-date="
                        report.bounds.max
                            ? dayjs(report.bounds.max).toDate()
                            : undefined
                    "
                    :disabled="!report.bounds.min"
                    trigger-class="h-10 gap-3 text-sm"
                    @update:model-value="changeRange"
                />
            </header>

            <ImportCoverage :coverage="report.coverage" />

            <EmptyState
                v-if="!report.bounds.min"
                data-testid="analytics-empty-state"
                :icon="IconChartBar"
                :title="$t('analytics.dashboard.no_data_title')"
                :description="$t('analytics.dashboard.no_data_body')"
            />

            <template v-else>
                <SummaryCards :report="report" />
                <TopPosts :top-posts="report.top_posts" :range="report.range" />
                <PerformanceTable
                    :rows="report.performance"
                    :range="report.range"
                />
                <FollowersChart
                    :followers="report.followers"
                    :range="report.range"
                    :colors="accountColors"
                />
                <PostsChart
                    :posts="report.posts"
                    :range="report.range"
                    :colors="accountColors"
                />
            </template>
        </div>
    </AppLayout>
</template>
