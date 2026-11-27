<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import AdminSortableTable, {
    type AdminTableColumn,
} from '@/components/admin/AdminSortableTable.vue';
import AdminStatGrid, {
    type AdminStat,
} from '@/components/admin/AdminStatGrid.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { filled } from '@/lib/helpers';
import { formatFullNumber } from '@/lib/utils';
import { index as failedPostsIndex } from '@/routes/admin/posts/failed';
import type { AdminUserPostsData } from '@/types/admin';

const props = defineProps<{
    data: AdminUserPostsData;
}>();

const STATUS_LABELS: Record<string, string> = {
    published: 'Published',
    failed: 'Failed',
    scheduled: 'Scheduled',
    publishing: 'Publishing',
    pending_approval: 'Needs approval',
    draft: 'Drafts',
};

const CONTENT_LABELS: Record<string, string> = {
    labels: 'Labels',
    signatures: 'Signatures',
    templates: 'Templates',
    ideas: 'Ideas',
    repurposes: 'Repurposes',
    rss_feeds: 'RSS feeds',
};

const statusStats = computed((): AdminStat[] => [
    { key: 'posts-total', label: 'Posts', value: formatFullNumber(props.data.total) },
    ...Object.entries(STATUS_LABELS).map(([status, label]) => ({
        key: `posts-${status}`,
        label,
        value: formatFullNumber(props.data.by_status[status] ?? 0),
    })),
]);

const contentStats = computed((): AdminStat[] =>
    Object.entries(CONTENT_LABELS).map(([key, label]) => ({
        key: `content-${key}`,
        label,
        value: formatFullNumber(props.data.content[key] ?? 0),
    })),
);

const NETWORK_COLUMNS: AdminTableColumn[] = [
    { key: 'network', label: 'Network' },
    { key: 'total', label: 'Posts', numeric: true },
    { key: 'published', label: 'Published', numeric: true },
    { key: 'failed', label: 'Failed', numeric: true },
    { key: 'scheduled', label: 'Scheduled', numeric: true },
    { key: 'draft', label: 'Drafts', numeric: true },
];

const networkLabel = (platform: string): string => {
    if (platform === 'none') {
        return 'No channel';
    }

    return getPlatformLabel(platform);
};

const networkRows = computed(() =>
    props.data.by_network.map((network) => ({
        ...network,
        network: networkLabel(network.platform),
    })),
);

const networkKey = (row: { platform: string }): string => row.platform;

/** Posts written before every post had a channel are grouped as `none`. */
const hasChannel = (platform: string): boolean => platform !== 'none';

const hasNetworks = computed(() => filled(props.data.by_network));
const hasFailures = computed(() => filled(props.data.recent_failures));

const failedAt = (value: string | null): string => {
    if (filled(value)) {
        return date.formatDateTime(value);
    }

    return '—';
};
</script>

<template>
    <div class="flex flex-col gap-6" data-testid="admin-user-posts">
        <AnalyticsSection
            title="Posts"
            subtitle="In the workspaces this user is in, by anyone, imported posts left out"
            flush
        >
            <AdminStatGrid :stats="statusStats" />
        </AnalyticsSection>

        <AnalyticsSection title="By network" flush>
            <p
                v-if="!hasNetworks"
                class="mx-4 mb-4 rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground sm:mx-5 sm:mb-5"
            >
                No posts yet.
            </p>
            <AdminSortableTable
                v-else
                :columns="NETWORK_COLUMNS"
                :rows="networkRows"
                :row-key="networkKey"
                default-sort="total"
                test-id="admin-user-posts-networks"
            >
                <template #cell-network="{ row }">
                    <span class="flex items-center gap-2.5">
                        <PlatformLogo
                            v-if="hasChannel(row.platform)"
                            :platform="row.platform"
                            size="xs"
                        />
                        <span class="text-sm font-medium text-foreground">{{
                            row.network
                        }}</span>
                    </span>
                </template>
            </AdminSortableTable>
        </AnalyticsSection>

        <AnalyticsSection title="Latest failures" flush>
            <template #actions>
                <Link
                    :href="failedPostsIndex.url()"
                    class="text-sm text-primary-text hover:underline"
                >
                    All failed posts
                </Link>
            </template>
            <p
                v-if="!hasFailures"
                class="mx-4 mb-4 rounded-lg border border-dashed border-border-strong px-5 py-10 text-center text-sm text-muted-foreground sm:mx-5 sm:mb-5"
            >
                No failed posts.
            </p>
            <ul v-else class="divide-y divide-border border-t border-border">
                <li
                    v-for="failure in data.recent_failures"
                    :key="failure.id"
                    class="flex items-start gap-3 px-4 py-3 sm:px-5"
                >
                    <PlatformLogo
                        v-if="failure.platform"
                        :platform="failure.platform"
                        size="xs"
                        class="mt-0.5"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="line-clamp-2 text-sm text-foreground">
                            {{ failure.error_message }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ failure.channel_name }} · {{ failure.workspace_name }}
                        </p>
                    </div>
                    <span class="shrink-0 text-xs text-muted-foreground tabular-nums">{{
                        failedAt(failure.failed_at)
                    }}</span>
                </li>
            </ul>
        </AnalyticsSection>

        <AnalyticsSection
            title="Content"
            subtitle="In the workspaces this user is in"
            flush
        >
            <AdminStatGrid :stats="contentStats" />
        </AnalyticsSection>
    </div>
</template>
