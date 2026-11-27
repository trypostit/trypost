<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    IconArticle,
    IconDots,
    IconFolders,
    IconMail,
    IconRocket,
    IconUsers,
} from '@tabler/icons-vue';
import Claude from '@thesvg/vue/claude';
import Github from '@thesvg/vue/github';
import Google from '@thesvg/vue/google';
import ProductHunt from '@thesvg/vue/product-hunt';
import Reddit from '@thesvg/vue/reddit';
import YCombinator from '@thesvg/vue/y-combinator';
import { computed } from 'vue';

import AdminDailyBarChart, {
    type AdminChartSeries,
} from '@/components/admin/AdminDailyBarChart.vue';
import AdminFunnelChart from '@/components/admin/AdminFunnelChart.vue';
import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import AdminBreakdownList, {
    type BreakdownArt,
} from '@/components/admin/insights/AdminBreakdownList.vue';
import AnalyticsRangePresets from '@/components/analytics/AnalyticsRangePresets.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { valueAt } from '@/composables/admin/useAdminDayAxis';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { metricChange } from '@/lib/adminChange';
import { formatUsd, NO_VALUE } from '@/lib/adminFormat';
import { accountColor } from '@/lib/analyticsColors';
import { isNumber } from '@/lib/helpers';
import { formatFullNumber, formatPercent, percentOf } from '@/lib/utils';
import { goalArt, personaArt, softwareArt, type WelcomeOptionArt } from '@/lib/welcomeOptions';
import { index as insightsIndex } from '@/routes/admin/billing/insights';
import type {
    AdminChange,
    AdminSignupInsights,
    AdminStat,
} from '@/types/admin';
import type { AnalyticsFilters, AnalyticsRangePreset, DateRange } from '@/types/analytics';

/** Logos sit on a white tile, like the Welcome software step, so dark marks stay readable in dark mode. */
const TILE = 'bg-white ring-1 ring-border';

const brand = (component: WelcomeOptionArt['icon']): BreakdownArt => ({ icon: component, tint: TILE });

/** A social network's own logo, as PlatformLogo draws it everywhere else. */
const network = (platform: string): BreakdownArt => ({ platform, tint: '' });

const SIGNUP_METHOD_ART: Record<string, BreakdownArt> = {
    google: brand(Google),
    github: brand(Github),
    email: brand(IconMail),
};

const REFERRAL_ART: Record<string, BreakdownArt> = {
    google: brand(Google),
    x: network('x'),
    linkedin: network('linkedin'),
    youtube: network('youtube'),
    tiktok: network('tiktok'),
    instagram: network('instagram'),
    threads: network('threads'),
    reddit: brand(Reddit),
    product_hunt: brand(ProductHunt),
    github: brand(Github),
    hacker_news: brand(YCombinator),
    ai_assistant: brand(Claude),
    directories: brand(IconFolders),
    friend: brand(IconUsers),
    founder: brand(IconRocket),
    blog: brand(IconArticle),
    other: brand(IconDots),
};

const props = defineProps<{
    insights: AdminSignupInsights;
    range: DateRange;
    ranges: Exclude<AnalyticsRangePreset, 'custom'>[];
    bounds: { min: string | null; max: string | null };
    filters: AnalyticsFilters;
}>();

const subtitle = computed((): string => date.formatDayMonthYearRange(props.range));

/** A median wait, in the unit it reads best in. */
const duration = (minutes: number | null): string => {
    if (!isNumber(minutes)) {
        return NO_VALUE;
    }

    if (minutes < 60) {
        return `${formatFullNumber(Math.round(minutes))} min`;
    }

    if (minutes < 48 * 60) {
        return `${formatFullNumber(Math.round(minutes / 60))} h`;
    }

    return `${formatFullNumber(Math.round(minutes / 1440))} days`;
};

/** Subscribing sooner is better, so a shorter wait reads green. */
const durationChange = computed((): AdminChange | null => {
    const current = props.insights.totals.minutes_to_subscribe;
    const past = props.insights.previous.minutes_to_subscribe;

    if (!isNumber(current)) {
        return null;
    }

    if (!isNumber(past)) {
        return null;
    }

    return metricChange(current, past, true);
});

const summary = computed((): AdminStat[] => [
    {
        key: 'insights-signups',
        label: 'Signups',
        value: formatFullNumber(props.insights.totals.signups),
        hint: 'Account owners; invited members left out',
        change: metricChange(props.insights.totals.signups, props.insights.previous.signups),
    },
    {
        key: 'insights-conversion',
        label: 'Conversion',
        value: formatPercent(props.insights.totals.subscribed_rate, 1),
        hint: `${formatFullNumber(props.insights.totals.subscribed)} of them subscribed`,
        change: metricChange(props.insights.totals.subscribed_rate, props.insights.previous.subscribed_rate),
    },
    {
        key: 'insights-mrr',
        label: 'New MRR',
        value: formatUsd(props.insights.totals.new_mrr_cents),
        hint: 'Their first subscriptions, at list price',
        change: metricChange(props.insights.totals.new_mrr_cents, props.insights.previous.new_mrr_cents),
    },
    {
        key: 'insights-time',
        label: 'Time to subscribe',
        value: duration(props.insights.totals.minutes_to_subscribe),
        hint: 'Median, from signing up',
        change: durationChange.value,
    },
]);

const subscribedOn = (index: number): number => valueAt(props.insights.subscribed_by_day, index);

/** Each day's signups, the ones that subscribed stacked on the ones that did not, so the bar is the day's signups. */
const signupSeries = computed((): AdminChartSeries[] => [
    {
        key: 'subscribed',
        label: 'Subscribed',
        color: accountColor(0),
        values: props.insights.subscribed_by_day,
    },
    {
        key: 'not-subscribed',
        label: 'Did not subscribe',
        color: 'var(--chart-4)',
        values: props.insights.signups_by_day.map((count, index) => count - subscribedOn(index)),
        details: (index) => [
            {
                label: 'Conversion',
                value: formatPercent(percentOf(subscribedOn(index), valueAt(props.insights.signups_by_day, index)), 1),
            },
        ],
    },
]);

const answeredOf = (answered: number): string =>
    `${formatFullNumber(answered)} of ${formatFullNumber(props.insights.totals.signups)} answered · subscribed is the share of each answer`;

const signupsSubtitle = computed((): string => `${subtitle.value} · against the period before`);

const goalsSubtitle = computed((): string => `${answeredOf(props.insights.answered.goals)}; one can pick several`);

const referralsSubtitle = computed(
    (): string =>
        `${formatFullNumber(props.insights.answered.referral_source)} answered; asked inside the app, so everyone who answered has access`,
);
</script>

<template>
    <Head title="Insights" />

    <AdminLayout title="Insights" description="Who signs up, what they tell us in Welcome, and who pays.">
        <template #actions>
            <AnalyticsRangePresets
                :filters="filters"
                :bounds="bounds"
                :url="insightsIndex.url()"
                :range="range"
                :presets="ranges"
            />
        </template>

        <div class="flex flex-col gap-6" data-testid="admin-insights">
            <AnalyticsSection title="Signups" :subtitle="signupsSubtitle" flush>
                <AdminStatGrid :stats="summary" />
                <div class="border-t border-border px-4 py-4 sm:px-5">
                    <AdminDailyBarChart
                        :days="insights.days"
                        :series="signupSeries"
                        show-total
                        test-id="admin-insights-signups"
                    />
                </div>
            </AnalyticsSection>

            <AnalyticsSection
                title="Funnel"
                subtitle="Everyone who signed up in the period, and how far they went · each pill is the share of the step before that did not go on"
                flush
            >
                <div class="border-t border-border px-4 py-5 sm:px-5">
                    <AdminFunnelChart :steps="insights.funnel" test-id="admin-insights-funnel" />
                </div>
            </AnalyticsSection>

            <div class="gap-6 lg:columns-2 [&>*]:mb-6 [&>*]:break-inside-avoid">
                <AdminBreakdownList
                    title="Who they are"
                    :subtitle="answeredOf(insights.answered.persona)"
                    :rows="insights.personas"
                    :answered="insights.answered.persona"
                    :art="personaArt"
                    test-id="admin-insights-personas"
                />
                <AdminBreakdownList
                    title="The tool they use"
                    :subtitle="answeredOf(insights.answered.software)"
                    :rows="insights.software"
                    :answered="insights.answered.software"
                    :art="softwareArt"
                    test-id="admin-insights-software"
                />
                <AdminBreakdownList
                    title="What they want"
                    :subtitle="goalsSubtitle"
                    :rows="insights.goals"
                    :answered="insights.answered.goals"
                    :art="goalArt"
                    test-id="admin-insights-goals"
                />
                <AdminBreakdownList
                    title="How they signed up"
                    subtitle="Subscribed is the share of each way"
                    :rows="insights.signup_methods"
                    :answered="insights.totals.signups"
                    :art="SIGNUP_METHOD_ART"
                    test-id="admin-insights-methods"
                />
                <AdminBreakdownList
                    title="How they found us"
                    :subtitle="referralsSubtitle"
                    :rows="insights.referral_sources"
                    :answered="insights.answered.referral_source"
                    :art="REFERRAL_ART"
                    :shows-subscribed="false"
                    test-id="admin-insights-referrals"
                />
            </div>
        </div>
    </AdminLayout>
</template>
