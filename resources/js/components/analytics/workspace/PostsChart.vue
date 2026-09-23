<script setup lang="ts">
import { ref } from 'vue';

import AnalyticsSection from './AnalyticsSection.vue';
import HorizontalBarChart from './charts/HorizontalBarChart.vue';
import StackedBarChart from './charts/StackedBarChart.vue';
import type { WorkspaceAnalyticsReport } from './types';

defineProps<{
    posts: WorkspaceAnalyticsReport['posts'];
    range: WorkspaceAnalyticsReport['range'];
}>();
const mode = ref<'bar' | 'stacked'>('stacked');
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.posts')"
        :subtitle="`${range.start} – ${range.end}`"
    >
        <template #actions>
            <div
                class="inline-flex rounded-lg border border-border bg-background p-1"
                role="group"
                :aria-label="$t('analytics.dashboard.posts_chart_mode')"
            >
                <button
                    type="button"
                    data-testid="posts-bar"
                    class="rounded-md px-3 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="
                        mode === 'bar'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="mode === 'bar'"
                    @click="mode = 'bar'"
                >
                    Bar
                </button>
                <button
                    type="button"
                    data-testid="posts-stacked"
                    class="rounded-md px-3 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="
                        mode === 'stacked'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="mode === 'stacked'"
                    @click="mode = 'stacked'"
                >
                    Stacked Bar
                </button>
            </div>
        </template>

        <div
            v-if="posts.accounts.length === 0"
            class="rounded-lg border border-dashed border-border px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_post_data') }}
        </div>
        <div
            v-else
            class="rounded-lg border border-border bg-background p-3 sm:p-5"
        >
            <HorizontalBarChart
                v-if="mode === 'bar'"
                :rows="
                    posts.accounts.map((account) => ({
                        account,
                        value: account.count,
                    }))
                "
            />
            <StackedBarChart
                v-else
                :accounts="posts.accounts"
                :buckets="posts.buckets"
            />
        </div>
    </AnalyticsSection>
</template>
