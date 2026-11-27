<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import AdminSortableTable from '@/components/admin/AdminSortableTable.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { joinFilled } from '@/lib/adminFormat';
import { customerName } from '@/lib/adminUsers';
import { filled } from '@/lib/helpers';
import { formatFullNumber } from '@/lib/utils';
import { show as userShow } from '@/routes/admin/users';
import type {
    AdminTableColumn,
    AdminUsageInsights,
} from '@/types/admin';

type TopAccount = AdminUsageInsights['top_accounts'][number];

const props = defineProps<{
    accounts: TopAccount[];
    subtitle: string;
}>();

const COLUMNS: AdminTableColumn[] = [
    { key: 'customer', label: 'Customer' },
    { key: 'posts', label: 'Posts sent', numeric: true },
    { key: 'automated', label: 'Posts via API and MCP', numeric: true },
    { key: 'channels', label: 'Channels connected', numeric: true },
    { key: 'failed', label: 'Posts failed', numeric: true },
];

/** Each account with what its customer cell shows: the name to sort on, and email and plan under it. */
const rows = computed(() =>
    props.accounts.map((account) => ({
        ...account,
        customer: customerName(account.name),
        detail: joinFilled([account.email, account.plan]),
    })),
);

const hasRows = computed(() => filled(props.accounts));

const rowKey = (row: TopAccount): string => row.account_id;

const profileUrl = (userId: string): string => userShow.url(userId);

const sectionSubtitle = computed(
    (): string => `${props.subtitle} · posts are counted in the period, channels as they are now · click a column to sort`,
);

/** Failures stand out only when there are some. */
const failedClass = (failed: number): string => {
    if (failed > 0) {
        return 'text-destructive';
    }

    return 'text-muted-foreground';
};
</script>

<template>
    <AnalyticsSection
        title="Top accounts"
        :subtitle="sectionSubtitle"
        flush
    >
        <p
            v-if="!hasRows"
            class="border-t border-border px-4 py-6 text-center text-sm text-muted-foreground sm:px-5"
        >
            Nobody sent a post in this period.
        </p>
        <AdminSortableTable
            v-else
            :columns="COLUMNS"
            :rows="rows"
            :row-key="rowKey"
            default-sort="posts"
            test-id="admin-user-insights-top-accounts"
        >
            <template #cell-customer="{ row }">
                <div class="grid min-w-0 max-w-72">
                    <Link
                        v-if="row.user_id"
                        :href="profileUrl(row.user_id)"
                        class="truncate text-sm font-emphasis text-foreground hover:underline"
                        >{{ row.customer }}</Link
                    >
                    <span v-else class="truncate text-sm text-muted-foreground">{{ row.customer }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{ row.detail }}</span>
                </div>
            </template>
            <template #cell-posts="{ row }">
                <span class="text-sm font-medium text-foreground">{{ formatFullNumber(row.posts) }}</span>
            </template>
            <template #cell-automated="{ row }">
                <span class="text-sm text-foreground">{{ formatFullNumber(row.automated) }}</span>
            </template>
            <template #cell-channels="{ row }">
                <span class="text-sm text-foreground">{{ formatFullNumber(row.channels) }}</span>
            </template>
            <template #cell-failed="{ row }">
                <span class="text-sm" :class="failedClass(row.failed)">{{ formatFullNumber(row.failed) }}</span>
            </template>
        </AdminSortableTable>
    </AnalyticsSection>
</template>
