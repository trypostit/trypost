<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import AnalyticsAccountSelector from '@/components/analytics/AnalyticsAccountSelector.vue';
import FacebookAnalytics from '@/components/analytics/FacebookAnalytics.vue';
import InstagramAnalytics from '@/components/analytics/InstagramAnalytics.vue';
import LinkedInPageAnalytics from '@/components/analytics/LinkedInPageAnalytics.vue';
import PinterestAnalytics from '@/components/analytics/PinterestAnalytics.vue';
import TelegramAnalytics from '@/components/analytics/TelegramAnalytics.vue';
import ThreadsAnalytics from '@/components/analytics/ThreadsAnalytics.vue';
import TikTokAnalytics from '@/components/analytics/TikTokAnalytics.vue';
import type { AnalyticsAccount } from '@/components/analytics/types';
import XAnalytics from '@/components/analytics/XAnalytics.vue';
import YouTubeAnalytics from '@/components/analytics/YouTubeAnalytics.vue';
import PageHeader from '@/components/PageHeader.vue';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import dayjs from '@/dayjs';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    accounts: AnalyticsAccount[];
}>();

const selectedAccountId = ref<string | null>(props.accounts[0]?.id ?? null);

const dateRange = ref({
    start: dayjs().subtract(6, 'day').toDate(),
    end: dayjs().toDate(),
});

const selectedAccount = computed(() =>
    props.accounts.find((a) => a.id === selectedAccountId.value),
);

const platformSupportsDateRange = computed(() => {
    if (!selectedAccount.value) return false;
    return [
        'instagram',
        'instagram-facebook',
        'facebook',
        'youtube',
        'pinterest',
        'threads',
        'x',
        'linkedin-page',
    ].includes(selectedAccount.value.platform);
});
</script>

<template>
    <AppLayout>
        <Head :title="trans('sidebar.analytics')" />

        <div
            class="mx-auto flex h-full w-full max-w-6xl flex-col gap-6 px-6 py-8"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <PageHeader :title="$t('sidebar.analytics')" />
                <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                    <AnalyticsAccountSelector
                        :accounts="accounts"
                        :selected-id="selectedAccountId"
                        @select="selectedAccountId = $event"
                    />
                    <DateRangePicker
                        v-if="platformSupportsDateRange"
                        v-model="dateRange"
                        trigger-class="h-auto gap-3 px-3 py-3 text-sm"
                    />
                </div>
            </div>

            <div
                v-if="accounts.length === 0"
                class="flex flex-1 items-center justify-center text-sm font-medium text-foreground/60"
            >
                {{ $t('analytics.no_accounts') }}
            </div>

            <div
                v-else-if="!selectedAccountId"
                class="flex flex-1 items-center justify-center text-sm font-medium text-foreground/60"
            >
                {{ $t('analytics.select_account') }}
            </div>

            <TikTokAnalytics
                v-else-if="selectedAccount?.platform === 'tiktok'"
                :account-id="selectedAccountId"
            />

            <InstagramAnalytics
                v-else-if="
                    selectedAccount?.platform === 'instagram' ||
                    selectedAccount?.platform === 'instagram-facebook'
                "
                :account-id="selectedAccountId"
                :date-range="dateRange"
            />

            <ThreadsAnalytics
                v-else-if="selectedAccount?.platform === 'threads'"
                :account-id="selectedAccountId"
                :date-range="dateRange"
            />

            <FacebookAnalytics
                v-else-if="selectedAccount?.platform === 'facebook'"
                :account-id="selectedAccountId"
                :date-range="dateRange"
            />

            <XAnalytics
                v-else-if="selectedAccount?.platform === 'x'"
                :account-id="selectedAccountId"
                :date-range="dateRange"
            />

            <LinkedInPageAnalytics
                v-else-if="selectedAccount?.platform === 'linkedin-page'"
                :account-id="selectedAccountId"
                :date-range="dateRange"
            />

            <PinterestAnalytics
                v-else-if="selectedAccount?.platform === 'pinterest'"
                :account-id="selectedAccountId"
                :date-range="dateRange"
            />

            <YouTubeAnalytics
                v-else-if="selectedAccount?.platform === 'youtube'"
                :account-id="selectedAccountId"
                :date-range="dateRange"
            />

            <TelegramAnalytics
                v-else-if="selectedAccount?.platform === 'telegram'"
                :account-id="selectedAccountId"
            />

            <div
                v-else
                class="flex flex-1 items-center justify-center text-sm font-medium text-foreground/60"
            >
                {{ $t('analytics.no_data') }}
            </div>
        </div>
    </AppLayout>
</template>
