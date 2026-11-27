<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    IconCalendarWeek,
    IconClock,
    IconDeviceDesktop,
    IconMail,
    IconMoon,
    IconSend,
    IconSun,
    IconWorld,
} from '@tabler/icons-vue';
import Github from '@thesvg/vue/github';
import Google from '@thesvg/vue/google';
import { computed, type Component } from 'vue';

import AdminDetailList from '@/components/admin/AdminDetailList.vue';
import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import ChurnSignalChip from '@/components/admin/ChurnSignalChip.vue';
import RiskLevelLabel from '@/components/admin/RiskLevelLabel.vue';
import StripeCustomerLink from '@/components/admin/StripeCustomerLink.vue';
import SessionDevice from '@/components/admin/user/SessionDevice.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import date from '@/date';
import { metricChange } from '@/lib/adminChange';
import { dateTimeOrDash, formatUsd, humanize, NO_VALUE, orDash } from '@/lib/adminFormat';
import { blank, filled, transform } from '@/lib/helpers';
import { formatFullNumber, formatPercent } from '@/lib/utils';
import type { Language } from '@/types';
import type {
    AdminDetailRow,
    AdminStat,
    AdminUserHealth,
    AdminUserOverview,
} from '@/types/admin';

const props = defineProps<{
    data: AdminUserOverview;
}>();

/** Whether the user signs in at all: a password, Google or GitHub. */
const hasSignInMethod = computed((): boolean => {
    if (props.data.sign_in.password) {
        return true;
    }

    if (props.data.sign_in.google) {
        return true;
    }

    return props.data.sign_in.github;
});

const hasLastSession = computed(() => filled(props.data.last_seen.user_agent));

const account = computed((): AdminDetailRow[] => [
    { key: 'sign-in', label: 'Signs in with' },
    { key: 'verified', label: 'Email verified', value: dateTimeOrDash(props.data.sign_in.email_verified_at) },
    { key: 'signup', label: 'Signed up', value: dateTimeOrDash(props.data.signup.at) },
    { key: 'signup-ip', label: 'Signup IP', value: orDash(props.data.signup.ip) },
    { key: 'last-seen', label: 'Last seen', value: dateTimeOrDash(props.data.last_seen.at) },
    { key: 'last-ip', label: 'Last IP', value: orDash(props.data.last_seen.ip) },
    { key: 'device', label: 'Last device' },
    { key: 'stripe', label: 'Stripe customer' },
]);

const healthStats = (health: AdminUserHealth): AdminStat[] => {
    const stats: AdminStat[] = [
        {
            key: 'health-last-post',
            label: 'Last post',
            value: lastPost(health.last_posted_at),
            hint: humanize(health.stage),
        },
        {
            key: 'health-posts',
            label: 'Posts in 30 days',
            value: formatFullNumber(health.posts.sent),
            hint: `${formatFullNumber(health.posts.automated)} via API or MCP`,
            change: metricChange(health.posts.sent, health.posts.previous),
        },
        {
            key: 'health-failure-rate',
            label: 'Failure rate',
            value: formatPercent(health.posts.failure_rate, 1),
            hint: `${formatFullNumber(health.posts.failed)} failed in 30 days`,
        },
        {
            key: 'health-channels',
            label: 'Channels',
            value: formatFullNumber(health.channels.total),
            hint: `${formatFullNumber(health.channels.broken)} need reconnecting`,
        },
    ];

    if (filled(health.risk)) {
        stats.push({
            key: 'health-mrr',
            label: 'MRR',
            value: formatUsd(health.risk.mrr_cents),
            hint: 'At list price',
        });
    }

    return stats;
};

const lastPost = (value: string | null): string => {
    if (filled(value)) {
        return date.diffForHumans(value);
    }

    return 'Never';
};

const lifecycle = computed((): AdminDetailRow[] => [
    { key: 'stage', label: 'Lifecycle stage', value: humanize(props.data.lifecycle.stage) },
    { key: 'activated', label: 'Activated', value: dateTimeOrDash(props.data.lifecycle.activated_at) },
    { key: 'first-paid', label: 'First paid', value: dateTimeOrDash(props.data.lifecycle.first_paid_at) },
    { key: 'onboarding', label: 'Finished onboarding', value: dateTimeOrDash(props.data.lifecycle.onboarding_completed_at) },
]);

const welcome = computed((): AdminDetailRow[] => [
    { key: 'persona', label: 'Persona', value: humanize(props.data.welcome.persona) },
    { key: 'goals', label: 'Goals', value: goalsLabel.value },
    { key: 'software', label: 'Used before', value: humanize(props.data.welcome.previous_software) },
    { key: 'referral', label: 'Found us through', value: humanize(props.data.welcome.referral_source) },
]);

const goalsLabel = computed((): string => {
    if (blank(props.data.welcome.goals)) {
        return NO_VALUE;
    }

    return props.data.welcome.goals.map(humanize).join(', ');
});

/** Every preference shows through a slot below; the rows only give the labels. */
const preferences: AdminDetailRow[] = [
    { key: 'locale', label: 'Language' },
    { key: 'timezone', label: 'Time zone' },
    { key: 'theme', label: 'Theme' },
    { key: 'time-format', label: 'Time format' },
    { key: 'week', label: 'Week starts on' },
    { key: 'post-action', label: 'Default posting action' },
];

const page = usePage();

/** The user's language as the app lists it, with its flag. */
const language = computed((): Language | undefined => {
    const languages = page.props.languages as Language[] | undefined;

    if (filled(languages)) {
        return languages.find((option) => option.code === props.data.signup.locale);
    }

    return undefined;
});

const THEME_ICONS: Record<string, Component> = {
    light: IconSun,
    dark: IconMoon,
    system: IconDeviceDesktop,
};

const themeIcon = (theme: string | null): Component => {
    const icon = transform(theme, (value) => THEME_ICONS[value]);

    if (filled(icon)) {
        return icon;
    }

    return IconDeviceDesktop;
};

/** Each preference row's icon, as the app's preferences page draws it. */
const preferenceIcons = computed((): { key: string; icon: Component; value: string }[] => [
    { key: 'timezone', icon: IconWorld, value: orDash(props.data.signup.timezone) },
    { key: 'theme', icon: themeIcon(props.data.preferences.theme), value: humanize(props.data.preferences.theme) },
    { key: 'time-format', icon: IconClock, value: orDash(props.data.preferences.time_format) },
    { key: 'week', icon: IconCalendarWeek, value: humanize(props.data.preferences.week_starts_on) },
    { key: 'post-action', icon: IconSend, value: humanize(props.data.preferences.default_post_action) },
]);

const attribution = computed((): AdminDetailRow[] =>
    Object.entries(props.data.attribution).map(([key, value]) => ({
        key,
        label: key,
        value,
    })),
);

const hasAttribution = computed(() => filled(attribution.value));
</script>

<template>
    <div class="flex flex-col gap-10" data-testid="admin-user-overview">
        <AnalyticsSection
            v-if="data.health"
            title="Health"
            subtitle="Posts in the last 30 days, against the 30 before"
            flush
            data-testid="admin-user-health"
        >
            <AdminStatGrid :stats="healthStats(data.health)" />
            <div
                v-if="data.health.risk"
                class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-border px-4 py-3 sm:px-5"
                data-testid="admin-user-health-risk"
            >
                <span class="text-sm text-muted-foreground">Churn risk</span>
                <template v-if="data.health.risk.level">
                    <RiskLevelLabel :level="data.health.risk.level" />
                    <span class="flex flex-wrap gap-1">
                        <ChurnSignalChip
                            v-for="signal in data.health.risk.signals"
                            :key="signal"
                            :signal="signal"
                        />
                    </span>
                </template>
                <span v-else class="text-sm text-foreground">No signal</span>
            </div>
        </AnalyticsSection>

        <SettingsSection title="Account">
            <AdminDetailList :rows="account">
                <template #row-sign-in>
                    <span
                        class="flex flex-wrap items-center justify-end gap-3"
                        data-testid="admin-user-sign-in"
                    >
                        <span
                            v-if="data.sign_in.password"
                            class="inline-flex items-center gap-1.5"
                            data-testid="admin-user-sign-in-password"
                        >
                            <IconMail class="size-4 text-muted-foreground" />
                            Email
                        </span>
                        <span
                            v-if="data.sign_in.google"
                            class="inline-flex items-center gap-1.5"
                            data-testid="admin-user-sign-in-google"
                        >
                            <Google aria-hidden="true" class="size-4 shrink-0" />
                            Google
                        </span>
                        <span
                            v-if="data.sign_in.github"
                            class="inline-flex items-center gap-1.5"
                            data-testid="admin-user-sign-in-github"
                        >
                            <Github
                                aria-hidden="true"
                                class="size-4 shrink-0 text-foreground [&_path]:fill-current"
                            />
                            GitHub
                        </span>
                        <span v-if="!hasSignInMethod">—</span>
                    </span>
                </template>
                <template #row-device>
                    <SessionDevice
                        v-if="hasLastSession"
                        :user-agent="data.last_seen.user_agent"
                        data-testid="admin-user-last-device"
                    />
                    <span v-else>—</span>
                </template>
                <template #row-stripe>
                    <StripeCustomerLink v-if="data.links.stripe" :url="data.links.stripe" />
                    <span v-else>—</span>
                </template>
            </AdminDetailList>
        </SettingsSection>

        <SettingsSection title="Lifecycle">
            <AdminDetailList :rows="lifecycle" />
        </SettingsSection>

        <SettingsSection
            title="Welcome answers"
            description="What they told us when they signed up."
        >
            <AdminDetailList :rows="welcome" />
        </SettingsSection>

        <SettingsSection
            title="Attribution"
            description="Where the signup came from (UTM and ad click ids)."
        >
            <AdminDetailList v-if="hasAttribution" :rows="attribution" />
            <AdminEmptyState v-else>No attribution recorded.</AdminEmptyState>
        </SettingsSection>

        <SettingsSection title="Preferences">
            <AdminDetailList :rows="preferences">
                <template #row-locale>
                    <span
                        v-if="language"
                        class="inline-flex items-center gap-1.5"
                        data-testid="admin-user-language"
                    >
                        <img
                            :src="language.flag"
                            alt=""
                            class="size-4 shrink-0 rounded-full object-cover"
                        />
                        {{ language.name }}
                    </span>
                    <span v-else>—</span>
                </template>
                <template v-for="row in preferenceIcons" :key="row.key" #[`row-${row.key}`]>
                    <span class="inline-flex items-center gap-1.5">
                        <component :is="row.icon" class="size-4 text-muted-foreground" aria-hidden="true" />
                        {{ row.value }}
                    </span>
                </template>
            </AdminDetailList>
        </SettingsSection>
    </div>
</template>
