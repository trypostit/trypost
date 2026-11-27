<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconCircleCheckFilled,
    IconClockExclamation,
    IconGauge,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import AdminStatGrid from '@/components/admin/AdminStatGrid.vue';
import PublishingBarChart from '@/components/admin/PublishingBarChart.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { countLabel } from '@/lib/adminFormat';
import { filled } from '@/lib/helpers';
import { formatFullNumber, formatPercent } from '@/lib/utils';
import { index as networksIndex } from '@/routes/admin/networks';
import { index as postsIndex } from '@/routes/admin/posts';
import { index as failedIndex } from '@/routes/admin/posts/failed';
import { index as quotasIndex } from '@/routes/admin/quotas';
import type {
    AdminDashboard,
    AdminStat,
} from '@/types/admin';

type FailingNetwork = AdminDashboard['attention']['networks'][number];
type QuotaAlert = AdminDashboard['attention']['quotas'][number];

const props = defineProps<{
    dashboard: AdminDashboard;
}>();

const attention = computed(() => props.dashboard.attention);

const isStuck = computed(() => attention.value.stuck_publishing > 0);

const needsAttention = computed((): boolean => {
    if (filled(attention.value.networks)) {
        return true;
    }

    if (filled(attention.value.quotas)) {
        return true;
    }

    return isStuck.value;
});

const networkDetail = (network: FailingNetwork): string =>
    `${formatFullNumber(network.failed)} failed in 24h · ${formatPercent(network.failure_rate, 1)} of its posts`;

const failedPostsUrl = (network: FailingNetwork): string =>
    failedIndex.url({ query: { networks: [network.network] } });

const stuckLabel = computed(
    (): string =>
        `${countLabel(attention.value.stuck_publishing, 'post', 'posts')} stuck publishing for over 30 minutes`,
);

const quotaUrl = (alert: QuotaAlert): string =>
    quotasIndex.url({ query: { network: alert.source } });

const last24h = computed((): AdminStat[] => [
    {
        key: 'published-24h',
        label: 'Published',
        value: formatFullNumber(props.dashboard.last_24h.published),
    },
    {
        key: 'failed-24h',
        label: 'Failed',
        value: formatFullNumber(props.dashboard.last_24h.failed),
    },
    {
        key: 'rate-24h',
        label: 'Failure rate',
        value: formatPercent(props.dashboard.last_24h.failure_rate, 1),
    },
]);

const queue = computed((): AdminStat[] => [
    {
        key: 'queue-scheduled',
        label: 'Scheduled',
        value: formatFullNumber(props.dashboard.queue.scheduled),
    },
    {
        key: 'queue-publishing',
        label: 'Publishing now',
        value: formatFullNumber(props.dashboard.queue.publishing),
    },
    {
        key: 'queue-retrying',
        label: 'Retrying',
        value: formatFullNumber(props.dashboard.queue.retrying),
    },
    {
        key: 'queue-review',
        label: 'In network review',
        value: formatFullNumber(props.dashboard.queue.pending_review),
    },
]);

const channels = computed((): AdminStat[] => [
    {
        key: 'channels-total',
        label: 'Channels',
        value: formatFullNumber(props.dashboard.channels.total),
    },
    {
        key: 'channels-week',
        label: 'New in 7 days',
        value: formatFullNumber(props.dashboard.channels.last_7_days),
    },
    {
        key: 'channels-connected',
        label: 'Connected',
        value: formatFullNumber(props.dashboard.channels.connected),
    },
    {
        key: 'channels-reconnect',
        label: 'Need reconnecting',
        value: formatFullNumber(props.dashboard.channels.to_reconnect),
    },
]);
</script>

<template>
    <Head title="Admin" />

    <AdminLayout
        title="Dashboard"
        description="How TryPost is doing right now."
    >
        <div class="flex flex-col gap-6" data-testid="admin-dashboard">
            <AnalyticsSection title="Needs attention" flush>
                <p
                    v-if="!needsAttention"
                    class="flex items-center gap-2 border-t border-border px-4 py-4 text-sm text-foreground sm:px-5"
                    data-testid="admin-attention-clear"
                >
                    <IconCircleCheckFilled class="size-5 text-success-text" />
                    Nothing needs attention right now.
                </p>
                <ul v-else class="divide-y divide-border border-t border-border">
                    <li
                        v-for="network in attention.networks"
                        :key="network.platform"
                        class="flex items-center gap-3 px-4 py-3 sm:px-5"
                        :data-testid="`admin-attention-network-${network.platform}`"
                    >
                        <PlatformLogo :platform="network.platform" size="xs" />
                        <div class="grid min-w-0 flex-1">
                            <span class="truncate text-sm font-emphasis text-foreground">{{
                                network.label
                            }}</span>
                            <span class="truncate text-xs text-destructive">{{
                                networkDetail(network)
                            }}</span>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="failedPostsUrl(network)">
                                Failed posts
                                <IconArrowRight class="size-4" />
                            </Link>
                        </Button>
                    </li>
                    <li
                        v-if="isStuck"
                        class="flex items-center gap-3 px-4 py-3 sm:px-5"
                        data-testid="admin-attention-stuck"
                    >
                        <span
                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-md bg-warning/15 text-warning"
                        >
                            <IconClockExclamation class="size-4" />
                        </span>
                        <div class="grid min-w-0 flex-1">
                            <span class="truncate text-sm font-emphasis text-foreground">{{
                                stuckLabel
                            }}</span>
                            <span class="truncate text-xs text-muted-foreground"
                                >A queue worker may be down. Check Horizon.</span
                            >
                        </div>
                    </li>
                    <li
                        v-for="alert in attention.quotas"
                        :key="`${alert.source}-${alert.label}`"
                        class="flex items-center gap-3 px-4 py-3 sm:px-5"
                        :data-testid="`admin-attention-quota-${alert.source}`"
                    >
                        <span
                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-md bg-warning/15 text-warning"
                        >
                            <IconGauge class="size-4" />
                        </span>
                        <div class="grid min-w-0 flex-1">
                            <span class="truncate text-sm font-emphasis text-foreground">{{
                                alert.label
                            }}</span>
                            <span class="truncate text-xs text-muted-foreground">{{
                                alert.detail
                            }}</span>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="quotaUrl(alert)">
                                Quotas
                                <IconArrowRight class="size-4" />
                            </Link>
                        </Button>
                    </li>
                </ul>
            </AnalyticsSection>

            <AnalyticsSection title="Last 24 hours" flush>
                <template #actions>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="networksIndex.url()">
                            Networks
                            <IconArrowRight class="size-4" />
                        </Link>
                    </Button>
                </template>
                <AdminStatGrid :stats="last24h" />
            </AnalyticsSection>

            <AnalyticsSection
                title="Publishing queue"
                subtitle="Posts waiting to go out, right now"
                flush
            >
                <AdminStatGrid :stats="queue" />
            </AnalyticsSection>

            <AnalyticsSection
                title="Posts per day"
                subtitle="Last 14 days"
            >
                <template #actions>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="postsIndex.url()">
                            Publishing
                            <IconArrowRight class="size-4" />
                        </Link>
                    </Button>
                </template>
                <PublishingBarChart :days="dashboard.publishing_days" />
            </AnalyticsSection>

            <AnalyticsSection title="Channels" flush>
                <template #actions>
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="networksIndex.url()">
                            Networks
                            <IconArrowRight class="size-4" />
                        </Link>
                    </Button>
                </template>
                <AdminStatGrid :stats="channels" />
            </AnalyticsSection>
        </div>
    </AdminLayout>
</template>
