<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    IconAffiliate,
    IconBuildingCommunity,
    IconUsers,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import UsageMetricCard from '@/components/settings/UsageMetricCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { edit as accountEdit } from '@/routes/app/account';
import { index as billingIndex } from '@/routes/app/billing';
import { index as usageIndex } from '@/routes/app/usage';

interface UsageData {
    workspaceCount: number;
    socialAccountCount: number;
    memberCount: number;
}

defineProps<{
    usage: UsageData;
}>();

const tabs = computed(() => [
    { name: 'account', label: trans('settings.account.tabs.account'), href: accountEdit().url },
    { name: 'usage', label: trans('settings.account.tabs.usage'), href: usageIndex().url },
    { name: 'billing', label: trans('settings.account.tabs.billing'), href: billingIndex().url },
]);
</script>

<template>
    <Head :title="$t('usage.title')" />

    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <PageHeader
                :title="$t('settings.hub.title')"
                :description="$t('settings.hub.description')"
            />

            <SettingsTabsNav :tabs="tabs" active="usage" />

            <section class="space-y-12">
                <div class="space-y-6">
                    <HeadingSmall
                        :title="$t('usage.section_account')"
                        :description="$t('usage.section_account_description')"
                    />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <UsageMetricCard
                            :label="$t('usage.workspaces')"
                            :icon="IconBuildingCommunity"
                            :current="usage.workspaceCount"
                            tone="violet"
                            rotate="-rotate-2"
                        />

                        <UsageMetricCard
                            :label="$t('usage.social_accounts')"
                            :icon="IconAffiliate"
                            :current="usage.socialAccountCount"
                            tone="amber"
                            rotate="rotate-1"
                        />

                        <UsageMetricCard
                            :label="$t('usage.members')"
                            :icon="IconUsers"
                            :current="usage.memberCount"
                            tone="emerald"
                            rotate="-rotate-1"
                        />
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
