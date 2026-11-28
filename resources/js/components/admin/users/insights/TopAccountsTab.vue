<script setup lang="ts">
import { computed } from 'vue';

import AdminCustomerCell from '@/components/admin/shared/AdminCustomerCell.vue';
import AdminEmptyState from '@/components/admin/shared/AdminEmptyState.vue';
import AdminSortableTable from '@/components/admin/shared/AdminSortableTable.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { joinFilled } from '@/lib/admin/format';
import { customerName, profileUrl } from '@/lib/admin/users';
import { filled, transform } from '@/lib/helpers';
import { formatFullNumber } from '@/lib/utils';
import type { AdminTableColumn } from '@/types/admin/shared';
import type { AdminUsageInsights } from '@/types/admin/userInsights';

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

/** Each account with what its customer cell shows: the name to sort on, its profile, and email and plan under it. */
const rows = computed(() =>
    props.accounts.map((account) => ({
        ...account,
        customer: customerName(account.name),
        href: transform(account.user_id, profileUrl),
        detail: joinFilled([account.email, account.plan]),
    })),
);

const hasRows = computed(() => filled(props.accounts));

const rowKey = (row: TopAccount): string => row.account_id;

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
        <AdminEmptyState v-if="!hasRows" variant="section">Nobody sent a post in this period.</AdminEmptyState>
        <AdminSortableTable
            v-else
            :columns="COLUMNS"
            :rows="rows"
            :row-key="rowKey"
            default-sort="posts"
            test-id="admin-user-insights-top-accounts"
        >
            <template #cell-customer="{ row }">
                <AdminCustomerCell :name="row.name" :href="row.href" :detail="row.detail" class="max-w-72" />
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
