<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
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

onMounted(() => {
    if (importRunning.value) {
        startImportPolling();
    }
});

watch(importRunning, (running) => {
    if (running) {
        startImportPolling();
    } else {
        stopImportPolling();
    }
});

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
        <Head :title="trans('sidebar.analytics')" />
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <header class="flex flex-wrap items-end justify-between gap-4">
                <PageHeader
                    :title="$t('sidebar.analytics')"
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

            <div
                v-if="!report.bounds.min"
                class="rounded-xl border-2 border-dashed border-foreground/35 bg-card px-6 py-16 text-center"
            >
                <h2 class="text-base font-semibold">
                    {{ $t('analytics.dashboard.no_data_title') }}
                </h2>
                <p class="mx-auto mt-2 max-w-lg text-sm text-muted-foreground">
                    {{ $t('analytics.dashboard.no_data_body') }}
                </p>
            </div>

            <template v-else>
                <SummaryCards :report="report" />
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
                <TopPosts :top-posts="report.top_posts" :range="report.range" />
                <PerformanceTable
                    :rows="report.performance"
                    :range="report.range"
                />
            </template>
        </div>
    </AppLayout>
</template>
