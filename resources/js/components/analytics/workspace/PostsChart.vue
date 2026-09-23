<script setup lang="ts">
import { ref } from 'vue';

import dayjs from '@/dayjs';
import { formatNumberCompact } from '@/lib/utils';

import AccountIdentity from './AccountIdentity.vue';
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
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.posts')"
        :subtitle="`${dayjs(range.start).format('D MMM YYYY')} – ${dayjs(range.end).format('D MMM YYYY')}`"
    >
        <template #actions>
            <div
                class="inline-flex rounded-lg border-2 border-foreground bg-card p-1 shadow-xs"
                role="group"
                :aria-label="$t('analytics.dashboard.posts_chart_mode')"
            >
                <button
                    type="button"
                    data-testid="posts-bar"
                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                    :class="
                        mode === 'bar'
                            ? 'bg-violet-100 text-foreground'
                            : 'text-foreground/65 hover:bg-muted hover:text-foreground'
                    "
                    :aria-pressed="mode === 'bar'"
                    @click="mode = 'bar'"
                >
                    {{ $t('analytics.dashboard.chart_bar') }}
                </button>
                <button
                    type="button"
                    data-testid="posts-stacked"
                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                    :class="
                        mode === 'stacked'
                            ? 'bg-violet-100 text-foreground'
                            : 'text-foreground/65 hover:bg-muted hover:text-foreground'
                    "
                    :aria-pressed="mode === 'stacked'"
                    @click="mode = 'stacked'"
                >
                    {{ $t('analytics.dashboard.chart_stacked_bar') }}
                </button>
            </div>
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
