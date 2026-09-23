<script setup lang="ts">
import { ref } from 'vue';

import { formatNumberCompact } from '@/lib/utils';

import AccountIdentity from './AccountIdentity.vue';
import AnalyticsSection from './AnalyticsSection.vue';
import HorizontalBarChart from './charts/HorizontalBarChart.vue';
import LineChart from './charts/LineChart.vue';
import { accountColor, type WorkspaceAnalyticsReport } from './types';

defineProps<{
    followers: WorkspaceAnalyticsReport['followers'];
    range: WorkspaceAnalyticsReport['range'];
}>();
const mode = ref<'line' | 'bar' | 'growth'>('line');
const buttons = [
    { mode: 'line', label: 'Line', test: 'followers-line' },
    { mode: 'bar', label: 'Bar', test: 'followers-bar' },
    { mode: 'growth', label: 'Growth', test: 'followers-growth' },
] as const;
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.followers')"
        :subtitle="`${range.start} – ${range.end}`"
    >
        <template #actions>
            <div
                class="inline-flex rounded-lg border border-border bg-background p-1"
                role="group"
                :aria-label="$t('analytics.dashboard.followers_chart_mode')"
            >
                <button
                    v-for="button in buttons"
                    :key="button.mode"
                    type="button"
                    :data-testid="button.test"
                    class="rounded-md px-3 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="
                        mode === button.mode
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="mode === button.mode"
                    @click="mode = button.mode"
                >
                    {{ button.label }}
                </button>
            </div>
        </template>

        <div
            v-if="followers.accounts.length === 0"
            class="rounded-lg border border-dashed border-border px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_follower_data') }}
        </div>
        <template v-else>
            <div class="mb-4 flex flex-wrap items-baseline gap-2">
                <span class="text-3xl font-semibold tabular-nums">{{
                    followers.total === null
                        ? '—'
                        : formatNumberCompact(followers.total)
                }}</span>
                <span class="text-xs text-muted-foreground">{{
                    $t('analytics.dashboard.total_followers')
                }}</span>
            </div>
            <div
                class="overflow-x-auto rounded-lg border border-border bg-background p-3 sm:p-5"
            >
                <LineChart
                    v-if="mode === 'line'"
                    :accounts="followers.accounts"
                    :series="followers.series"
                />
                <HorizontalBarChart
                    v-else
                    :rows="
                        followers.accounts.map((account) => ({
                            account,
                            value:
                                mode === 'growth'
                                    ? account.growth
                                    : account.value,
                        }))
                    "
                    :growth="mode === 'growth'"
                />
            </div>
            <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2">
                <div
                    v-for="(account, index) in followers.accounts"
                    :key="account.social_account_key"
                    class="flex items-center gap-2"
                >
                    <AccountIdentity
                        :account="account"
                        :color="accountColor(index)"
                    />
                    <span
                        v-if="account.provenance === 'carried_forward'"
                        class="text-[11px] text-muted-foreground"
                        :title="$t('analytics.dashboard.carried_forward_hint')"
                        >{{ $t('analytics.dashboard.carried_forward') }}</span
                    >
                </div>
            </div>
        </template>
    </AnalyticsSection>
</template>
