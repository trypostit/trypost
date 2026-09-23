<script setup lang="ts">
import { ref } from 'vue';

import dayjs from '@/dayjs';
import { formatNumberCompact } from '@/lib/utils';

import AccountIdentity from './AccountIdentity.vue';
import AnalyticsSection from './AnalyticsSection.vue';
import HorizontalBarChart from './charts/HorizontalBarChart.vue';
import LineChart from './charts/LineChart.vue';
import type { WorkspaceAnalyticsReport } from './types';

const props = defineProps<{
    followers: WorkspaceAnalyticsReport['followers'];
    range: WorkspaceAnalyticsReport['range'];
    colors: Record<string, string>;
}>();
const observedDays = props.followers.series.filter((point) =>
    Object.values(point.accounts).some((value) => value !== null),
).length;
const mode = ref<'line' | 'bar' | 'growth'>(observedDays > 1 ? 'line' : 'bar');
const buttons = [
    { mode: 'line', label: 'Line', test: 'followers-line' },
    { mode: 'bar', label: 'Bar', test: 'followers-bar' },
    { mode: 'growth', label: 'Growth', test: 'followers-growth' },
] as const;
</script>

<template>
    <AnalyticsSection
        :title="$t('analytics.dashboard.followers')"
        :subtitle="`${dayjs(range.start).format('D MMM YYYY')} – ${dayjs(range.end).format('D MMM YYYY')}`"
    >
        <template #actions>
            <div
                class="inline-flex rounded-lg border-2 border-foreground bg-card p-1 shadow-xs"
                role="group"
                :aria-label="$t('analytics.dashboard.followers_chart_mode')"
            >
                <button
                    v-for="button in buttons"
                    :key="button.mode"
                    type="button"
                    :data-testid="button.test"
                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
                    :class="
                        mode === button.mode
                            ? 'bg-violet-100 text-foreground'
                            : 'text-foreground/65 hover:bg-muted hover:text-foreground'
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
            class="rounded-lg border-2 border-dashed border-foreground/30 px-5 py-12 text-center text-sm text-muted-foreground"
        >
            {{ $t('analytics.dashboard.no_follower_data') }}
        </div>
        <template v-else>
            <div class="mb-4 flex flex-wrap items-baseline gap-2">
                <span
                    class="text-4xl font-semibold tracking-tight tabular-nums"
                    >{{
                        followers.total === null
                            ? '—'
                            : formatNumberCompact(followers.total)
                    }}</span
                >
                <span class="text-sm text-muted-foreground">{{
                    $t('analytics.dashboard.total_followers')
                }}</span>
            </div>
            <div class="min-w-0 border-t border-foreground/15 pt-5">
                <LineChart
                    v-if="mode === 'line'"
                    :accounts="followers.accounts"
                    :series="followers.series"
                    :colors="colors"
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
                    :colors="colors"
                />
            </div>
            <div
                class="mt-5 grid gap-x-5 gap-y-2.5 border-t border-foreground/15 pt-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <div
                    v-for="account in followers.accounts"
                    :key="account.social_account_key"
                    class="flex min-w-0 items-center justify-between gap-2"
                >
                    <AccountIdentity :account="account" />
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="text-sm font-semibold tabular-nums">
                            {{
                                mode === 'growth'
                                    ? account.growth === null
                                        ? '—'
                                        : `${account.growth > 0 ? '+' : ''}${formatNumberCompact(account.growth)}`
                                    : account.value === null
                                      ? '—'
                                      : formatNumberCompact(account.value)
                            }}
                        </span>
                        <span
                            v-if="account.provenance === 'carried_forward'"
                            class="text-[11px] text-muted-foreground"
                            :title="
                                $t('analytics.dashboard.carried_forward_hint')
                            "
                            >{{
                                $t('analytics.dashboard.carried_forward')
                            }}</span
                        >
                    </span>
                </div>
            </div>
        </template>
    </AnalyticsSection>
</template>
