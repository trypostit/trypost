<script setup lang="ts">
import { ref } from 'vue';

import { formatNumberCompact } from '@/lib/utils';

import AccountIdentity from './AccountIdentity.vue';
import AnalyticsModeToggle from './AnalyticsModeToggle.vue';
import AnalyticsSection from './AnalyticsSection.vue';
import HorizontalBarChart from './charts/HorizontalBarChart.vue';
import StackedBarChart from './charts/StackedBarChart.vue';
import type { WorkspaceAnalyticsReport } from './types';

defineProps<{
    posts: WorkspaceAnalyticsReport['posts'];
    range: WorkspaceAnalyticsReport['range'];
    colors: Record<string, string>;
}>();
const mode = ref<'bar' | 'stacked'>('stacked');
const buttons = [
    { mode: 'bar', label: 'analytics.dashboard.chart_bar', test: 'posts-bar' },
    {
        mode: 'stacked',
        label: 'analytics.dashboard.chart_stacked_bar',
        test: 'posts-stacked',
    },
] as const;
</script>

<template>
    <AnalyticsSection :title="$t('analytics.dashboard.posts')" :range="range">
        <template #actions>
            <AnalyticsModeToggle
                v-model="mode"
                label="analytics.dashboard.posts_chart_mode"
                :options="buttons"
            />
        </template>

        <div
            v-if="posts.accounts.length === 0"
            class="rounded-lg border-2 border-dashed border-foreground/30 px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_post_data') }}
        </div>
        <template v-else>
            <div class="min-w-0 border-t border-foreground/15 pt-5">
                <HorizontalBarChart
                    v-if="mode === 'bar'"
                    :rows="
                        posts.accounts.map((account) => ({
                            account,
                            value: account.count,
                        }))
                    "
                    :colors="colors"
                />
                <StackedBarChart
                    v-else
                    :accounts="posts.accounts"
                    :buckets="posts.buckets"
                    :resolution="posts.resolution"
                    :colors="colors"
                />
            </div>
            <div
                class="mt-5 grid gap-x-5 gap-y-2.5 border-t border-foreground/15 pt-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="account in posts.accounts"
                    :key="account.social_account_key"
                    class="flex min-w-0 items-center justify-between gap-2"
                >
                    <AccountIdentity :account="account" />
                    <span class="shrink-0 text-sm font-semibold tabular-nums">
                        {{ formatNumberCompact(account.count) }}
                    </span>
                </div>
            </div>
        </template>
    </AnalyticsSection>
</template>
