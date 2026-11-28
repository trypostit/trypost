<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

import QuotaBar from '@/components/admin/quotas/QuotaBar.vue';
import AdminDailyBarChart, {
    type AdminChartSeries,
} from '@/components/admin/shared/AdminDailyBarChart.vue';
import AdminEmptyState from '@/components/admin/shared/AdminEmptyState.vue';
import AdminStatGrid from '@/components/admin/shared/AdminStatGrid.vue';
import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { useUrlTab } from '@/composables/admin/useUrlTab';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { countIn, humanize, joinFilled } from '@/lib/admin/format';
import { accountColor } from '@/lib/analyticsColors';
import { blank, filled, isNumber } from '@/lib/helpers';
import { formatFullNumber, formatMoney } from '@/lib/utils';
import { index as quotasIndex } from '@/routes/admin/quotas';
import type { AdminQuotaCards, AdminUsageDay, AdminXCostDay, AdminXCredits, AdminXUsage, AdminYouTubeQuota, QuotaSource } from '@/types/admin/quotas';
import type { AdminStat } from '@/types/admin/shared';
import type {
    AnalyticsFilters,
    AnalyticsRangePreset,
    DateRange,
} from '@/types/analytics';

/** The daily limit is the one Google names per day; a metric without it shows its largest. */
const DAILY_LIMIT_MARKER = 'PerDay';

const YOUTUBE_ENV = [
    'YOUTUBE_QUOTA_MONITORING_ENABLED=true',
    'YOUTUBE_QUOTA_GCP_PROJECT_ID=',
    'YOUTUBE_QUOTA_GCP_CREDENTIALS=',
];

const X_ENV = ['X_USAGE_MONITORING_ENABLED=true', 'X_BEARER_TOKEN='];

/** One tab per network that reports a quota, in this order; X opens by default. */
const QUOTA_NETWORKS: { value: QuotaSource; label: string; platform: string }[] = [
    { value: 'x', label: 'X', platform: 'x' },
    { value: 'youtube', label: 'YouTube', platform: 'youtube' },
];

const props = defineProps<{
    cards: AdminQuotaCards;
    range: DateRange;
    ranges: Exclude<AnalyticsRangePreset, 'custom'>[];
    filters: AnalyticsFilters;
}>();

/** The range buttons reload the page on the X tab. */
const X_TAB_QUERY = { network: 'x' };

/** X keeps 90 days, so there is no custom range: the bounds are never read. */
const NO_BOUNDS = { min: null, max: null };

/** The open tab lives in the URL; the range buttons keep it. */
const { tab: network, selectTab: selectNetwork } = useUrlTab({
    tabs: QUOTA_NETWORKS.map((tab) => tab.value),
    param: 'network',
    url: (mergeQuery) => quotasIndex.url({ mergeQuery }),
});

/** X reports its credits and prices in dollars; the money formatter takes cents. */
const toCents = (dollars: number): number => dollars * 100;

/** Spend and credits to the cent: a day of X often costs less than a dollar. */
const money = (dollars: number): string => formatMoney(toCents(dollars));

/** A unit price, to the tenth of a cent: X bills reads at $0.005. */
const unitPrice = (dollars: number): string =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 3 }).format(dollars);

const updatedLabel = (takenAt: string | null): string => {
    if (filled(takenAt)) {
        return `Updated ${date.diffForHumans(takenAt)}`;
    }

    return 'Not read yet: it runs every 6 hours';
};

const dailyLimit = (quota: AdminYouTubeQuota): number | null => {
    const daily = quota.limits.find((limit) =>
        limit.name.includes(DAILY_LIMIT_MARKER),
    );

    if (filled(daily)) {
        return daily.value;
    }

    if (blank(quota.limits)) {
        return null;
    }

    return Math.max(...quota.limits.map((limit) => limit.value));
};

/** Google's quota metric names, as people read them. */
const YOUTUBE_QUOTA_LABELS: Record<string, string> = {
    default: 'API units',
    video_insert: 'Video uploads',
    search_list: 'Searches',
};

const quotaKey = (quota: AdminYouTubeQuota): string =>
    quota.metric.replace('youtube.googleapis.com/', '');

/** A metric Google adds later reads as its own name, without underscores. */
const quotaLabel = (quota: AdminYouTubeQuota): string => {
    const key = quotaKey(quota);
    const label = YOUTUBE_QUOTA_LABELS[key];

    if (filled(label)) {
        return label;
    }

    return humanize(key);
};

const hasYouTubeQuotas = computed(() => filled(props.cards.youtube.quotas));

const youtubeSubtitle = computed((): string => {
    const updated = updatedLabel(props.cards.youtube.taken_at);

    if (filled(props.cards.youtube.since)) {
        return `${updated} · Today since ${date.formatDateTime(props.cards.youtube.since)}`;
    }

    return updated;
});

const credits = computed(() => props.cards.x.credits);
const usage = computed(() => props.cards.x.usage);

const creditStats = computed((): AdminStat[] => {
    const current = credits.value;

    if (filled(current)) {
        return [
            {
                key: 'x-total-balance',
                label: 'Credit balance',
                value: money(current.total_balance),
                hint: 'Prepaid plus free, never below zero',
            },
            {
                key: 'x-prepaid-balance',
                label: 'Prepaid',
                value: money(current.prepaid_balance),
                hint: 'Below zero when paid usage overdrew it',
            },
            {
                key: 'x-free-balance',
                label: 'Free credits',
                value: money(current.free_balance),
                hint: 'Left across unexpired free grants',
            },
        ];
    }

    return [];
});

const freeGrants = computed((): AdminXCredits['free_grants'] => {
    if (filled(credits.value)) {
        return credits.value.free_grants;
    }

    return [];
});

const hasFreeGrants = computed(() => filled(freeGrants.value));

const grantExpiry = (expiresAt: string | null): string => {
    if (filled(expiresAt)) {
        return `Expires ${date.formatDate(expiresAt)}`;
    }

    return 'No expiry';
};

const capSubtitle = computed((): string => {
    const current = usage.value;

    if (filled(current)) {
        return joinFilled([capResetLabel(current.cap_reset_day), projectLabel(current.project_id)]);
    }

    return '';
});

const capResetLabel = (day: number | null): string | null => {
    if (isNumber(day)) {
        return `Resets on day ${day} of the month`;
    }

    return null;
};

const projectLabel = (projectId: string | null): string | null => {
    if (filled(projectId)) {
        return `Project ${projectId}`;
    }

    return null;
};

const xSubtitle = computed((): string => updatedLabel(props.cards.x.taken_at));

const projectDays = computed((): AdminUsageDay[] => {
    if (filled(usage.value)) {
        return usage.value.daily_project_usage;
    }

    return [];
});

const usageDays = computed((): string[] => projectDays.value.map((day) => day.date));

/** Each day's usage, in the order of the chart's days; a day X reported nothing for reads 0. */
const valuesFor = (entries: AdminUsageDay[]): number[] => {
    const byDate: Record<string, number> = Object.fromEntries(entries.map((entry) => [entry.date, entry.usage]));

    return usageDays.value.map((day) => countIn(byDate, day));
};

/** An app X names by its id, else by its place in the list. */
const appLabel = (clientAppId: string | null, index: number): string => {
    if (filled(clientAppId)) {
        return `App ${clientAppId}`;
    }

    return `App ${index + 1}`;
};

const clientApps = computed((): NonNullable<AdminXUsage['daily_client_app_usage']> => {
    const apps = usage.value?.daily_client_app_usage;

    if (filled(apps)) {
        return apps;
    }

    return [];
});

/** Per client app when X breaks the usage down, else the project's daily total. */
const usageSeries = computed((): AdminChartSeries[] => {
    if (filled(clientApps.value)) {
        return clientApps.value.map((app, index) => ({
            key: `app_${index}`,
            label: appLabel(app.client_app_id, index),
            color: accountColor(index),
            values: valuesFor(app.usage),
        }));
    }

    return [
        {
            key: 'project',
            label: 'Posts read',
            color: 'var(--chart-3)',
            values: valuesFor(projectDays.value),
        },
    ];
});

const hasDailyUsage = computed(() => filled(usageDays.value));

/** The daily usage chart shows once X is set up and reported a day. */
const showsDailyUsage = computed((): boolean => {
    if (!props.cards.x.configured) {
        return false;
    }

    return hasDailyUsage.value;
});

const estimate = computed(() => props.cards.x.estimate);
const prices = computed(() => props.cards.x.prices);
const hasEstimate = computed(() => filled(estimate.value));
const estimateDays = computed(() => estimate.value.map((day) => day.date));

/** A series' line in the tooltip: how many posts, then what they cost (the chart's values are cents). */
const costLine = (pickCount: (day: AdminXCostDay) => number) => (index: number, cents: number): string => {
    const day = estimate.value[index];

    if (filled(day)) {
        return `${formatFullNumber(pickCount(day))} posts · ${formatMoney(cents)}`;
    }

    return formatMoney(cents);
};

const costSeries = computed((): AdminChartSeries[] => [
    {
        key: 'cost_reads',
        label: `Posts read (${unitPrice(prices.value.read)} each)`,
        color: 'var(--chart-1)',
        values: estimate.value.map((day) => toCents(day.cost_reads)),
        format: costLine((day) => day.reads),
    },
    {
        key: 'cost_created',
        label: `Posts created (${unitPrice(prices.value.create)} each)`,
        color: 'var(--chart-3)',
        values: estimate.value.map((day) => toCents(day.cost_created)),
        format: costLine((day) => day.created),
    },
    {
        key: 'cost_created_with_link',
        label: `Posts with a link (${unitPrice(prices.value.create_with_link)} each)`,
        color: 'var(--chart-5)',
        values: estimate.value.map((day) => toCents(day.cost_created_with_link)),
        format: costLine((day) => day.created_with_link),
    },
]);

const dayCost = (day: AdminXCostDay): number =>
    day.cost_reads + day.cost_created + day.cost_created_with_link;

const sumOf = (pick: (day: AdminXCostDay) => number): number =>
    estimate.value.reduce((total, day) => total + pick(day), 0);

const averagePerDay = (total: number): number => {
    if (blank(estimate.value)) {
        return 0;
    }

    return total / estimate.value.length;
};

const estimateStats = computed((): AdminStat[] => {
    const total = sumOf(dayCost);

    return [
        {
            key: 'x-estimate-total',
            label: `Estimated, last ${estimate.value.length} days`,
            value: money(total),
        },
        {
            key: 'x-estimate-daily',
            label: 'Estimated per day',
            value: money(averagePerDay(total)),
        },
        {
            key: 'x-estimate-reads',
            label: 'From reads',
            value: money(sumOf((day) => day.cost_reads)),
        },
        {
            key: 'x-estimate-links',
            label: 'From posts with a link',
            value: money(sumOf((day) => day.cost_created_with_link)),
        },
    ];
});

const projectUsage = computed((): number => {
    if (filled(usage.value)) {
        return usage.value.project_usage;
    }

    return 0;
});

const projectCap = computed((): number | null => {
    if (filled(usage.value)) {
        return usage.value.project_cap;
    }

    return null;
});
</script>

<template>
    <Head title="Quotas" />

    <AdminLayout
        title="Quotas and spend"
        description="What each network reports, read four times a day, so you can ask for more before it runs out."
    >
        <div class="flex flex-col gap-6" data-testid="admin-quotas">
            <Tabs
                :model-value="network"
                class="flex flex-col gap-6"
                @update:model-value="selectNetwork"
            >
                <TabsList variant="line">
                    <TabsTrigger
                        v-for="tab in QUOTA_NETWORKS"
                        :key="tab.value"
                        :value="tab.value"
                        :data-testid="`admin-quota-tab-${tab.value}`"
                    >
                        <PlatformBrandIcon :platform="tab.platform" colored class="size-4" />
                        {{ tab.label }}
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="youtube" class="flex flex-col gap-6">
                    <AnalyticsSection
                        title="YouTube API"
                        :subtitle="youtubeSubtitle"
                        flush
                    >
                        <div
                            v-if="!cards.youtube.configured"
                            class="border-t border-border px-4 py-5 sm:px-5"
                            data-testid="admin-quota-youtube-off"
                        >
                            <p class="text-sm text-muted-foreground">
                                Reads the project quota from Google Cloud Monitoring.
                                Create a service account with only the Monitoring Viewer
                                role in the project of the YouTube app, then set:
                            </p>
                            <pre
                                class="mt-3 overflow-x-auto rounded-lg bg-muted px-3 py-2 text-xs text-foreground"
                            ><code v-for="line in YOUTUBE_ENV" :key="line" class="block">{{ line }}</code></pre>
                        </div>
                        <div
                            v-else
                            class="flex flex-col gap-5 border-t border-border px-4 py-5 sm:px-5"
                            data-testid="admin-quota-youtube"
                        >
                            <AdminEmptyState v-if="!hasYouTubeQuotas" variant="inset">
                                No usage reported by Google yet.
                            </AdminEmptyState>
                            <QuotaBar
                                v-for="quota in cards.youtube.quotas"
                                :key="quota.metric"
                                :label="quotaLabel(quota)"
                                :used="quota.usage"
                                :limit="dailyLimit(quota)"
                                :test-id="`admin-quota-youtube-${quotaKey(quota)}`"
                            />
                        </div>
                    </AnalyticsSection>
                </TabsContent>

                <TabsContent value="x" class="flex flex-col gap-6">
                    <div
                        class="flex min-w-0 flex-wrap items-center justify-between gap-2"
                        data-testid="admin-quota-x-toolbar"
                    >
                        <AnalyticsRangePresets
                            :filters="filters"
                            :bounds="NO_BOUNDS"
                            :url="quotasIndex.url()"
                            :range="range"
                            :keep="X_TAB_QUERY"
                            :presets="ranges"
                            :custom="false"
                        />
                        <span class="text-xs text-muted-foreground"
                            >UTC days · X keeps the last 90 days</span
                        >
                    </div>

                    <AnalyticsSection title="X API" :subtitle="xSubtitle" flush>
                        <div
                            v-if="!cards.x.configured"
                            class="border-t border-border px-4 py-5 sm:px-5"
                            data-testid="admin-quota-x-off"
                        >
                            <p class="text-sm text-muted-foreground">
                                Reads the credit balance and the post usage with the
                                app's bearer token, from the X developer console. Set:
                            </p>
                            <pre
                                class="mt-3 overflow-x-auto rounded-lg bg-muted px-3 py-2 text-xs text-foreground"
                            ><code v-for="line in X_ENV" :key="line" class="block">{{ line }}</code></pre>
                        </div>
                        <template v-else>
                            <AdminStatGrid
                                v-if="credits"
                                :stats="creditStats"
                                data-testid="admin-quota-x-credits"
                            />
                            <ul
                                v-if="hasFreeGrants"
                                class="divide-y divide-border border-t border-border"
                                data-testid="admin-quota-x-grants"
                            >
                                <li
                                    v-for="(grant, index) in freeGrants"
                                    :key="index"
                                    class="flex items-center justify-between px-4 py-2.5 text-sm sm:px-5"
                                >
                                    <span class="text-foreground">Free grant</span>
                                    <span class="flex items-center gap-3 tabular-nums">
                                        <span class="text-muted-foreground">{{
                                            grantExpiry(grant.expires_at)
                                        }}</span>
                                        <span class="text-foreground">{{
                                            money(grant.amount)
                                        }}</span>
                                    </span>
                                </li>
                            </ul>
                            <div
                                v-if="usage"
                                class="flex flex-col gap-2 border-t border-border px-4 py-5 sm:px-5"
                            >
                                <QuotaBar
                                    label="Posts read this billing cycle"
                                    :used="projectUsage"
                                    :limit="projectCap"
                                    test-id="admin-quota-x-usage"
                                />
                                <p
                                    v-if="capSubtitle"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ capSubtitle }}
                                </p>
                            </div>
                        </template>
                    </AnalyticsSection>

                    <AnalyticsSection
                        v-if="hasEstimate"
                        title="Estimated cost per day"
                        subtitle="An estimate from X's public prices, not what X billed: the X developer console has that"
                        flush
                    >
                        <AdminStatGrid :stats="estimateStats" />
                        <div class="border-t border-border px-4 py-4 sm:px-5">
                            <AdminDailyBarChart
                                :days="estimateDays"
                                :series="costSeries"
                                currency
                                test-id="admin-quota-x-cost"
                            />
                        </div>
                    </AnalyticsSection>

                    <AnalyticsSection
                        v-if="showsDailyUsage"
                        title="X posts read per day"
                        subtitle="Deduplicated per UTC day, as X bills them"
                    >
                        <AdminDailyBarChart
                            :days="usageDays"
                            :series="usageSeries"
                            test-id="admin-quota-x-daily"
                        />
                    </AnalyticsSection>
                </TabsContent>
            </Tabs>
        </div>
    </AdminLayout>
</template>
