<script setup lang="ts">
import { computed } from 'vue';

import AdminBarList from '@/components/admin/AdminBarList.vue';
import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { metricChange } from '@/lib/adminChange';
import { formatFullNumber } from '@/lib/utils';
import type {
    AdminStat,
    AdminUsageInsights,
} from '@/types/admin';

const props = defineProps<{
    usage: AdminUsageInsights;
    subtitle: string;
}>();

const stats = computed((): AdminStat[] => [
    {
        key: 'usage-active',
        label: 'Active accounts',
        value: formatFullNumber(props.usage.totals.active_accounts),
        hint: 'Sent at least one post',
        change: metricChange(props.usage.totals.active_accounts, props.usage.previous.active_accounts),
    },
    {
        key: 'usage-posts',
        label: 'Posts sent',
        value: formatFullNumber(props.usage.totals.posts),
        change: metricChange(props.usage.totals.posts, props.usage.previous.posts),
    },
    {
        key: 'usage-per-account',
        label: 'Posts per active account',
        value: formatFullNumber(props.usage.totals.posts_per_active),
        change: metricChange(props.usage.totals.posts_per_active, props.usage.previous.posts_per_active),
    },
    {
        key: 'usage-channels',
        label: 'Channels per account',
        value: formatFullNumber(props.usage.totals.channels_per_account),
        hint: 'Accounts with a channel',
    },
]);

const stagesSubtitle = computed(
    (): string =>
        `${formatFullNumber(props.usage.lifecycle.customers)} accounts with access, by their channels and last post`,
);
</script>

<template>
    <div class="flex flex-col gap-6" data-testid="admin-user-insights-overview">
        <AnalyticsSection title="Usage" :subtitle="subtitle" flush>
            <AdminStatGrid :stats="stats" />
        </AnalyticsSection>

        <AdminBarList
            title="Customers by stage"
            :subtitle="stagesSubtitle"
            :rows="usage.lifecycle.stages"
            test-id="admin-user-insights-lifecycle"
        />

        <div class="grid items-start gap-6 lg:grid-cols-2">
            <AdminBarList
                title="Channels per account"
                subtitle="Accounts with a channel"
                :rows="usage.channel_buckets"
                test-id="admin-user-insights-channel-buckets"
            />
            <AdminBarList
                title="Posts per account"
                subtitle="Accounts that sent a post in the period"
                :rows="usage.post_buckets"
                test-id="admin-user-insights-post-buckets"
            />
        </div>
    </div>
</template>
