<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconEye, IconLock, IconLockOpen } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import AdminEmptyState from '@/components/admin/shared/AdminEmptyState.vue';
import AdminStatGrid from '@/components/admin/shared/AdminStatGrid.vue';
import BlockUserDialog from '@/components/admin/users/BlockUserDialog.vue';
import UserActivityTab from '@/components/admin/users/profile/UserActivityTab.vue';
import UserBillingTab from '@/components/admin/users/profile/UserBillingTab.vue';
import UserIntegrationsTab from '@/components/admin/users/profile/UserIntegrationsTab.vue';
import UserOverviewTab from '@/components/admin/users/profile/UserOverviewTab.vue';
import UserPostsTab from '@/components/admin/users/profile/UserPostsTab.vue';
import UserWorkspacesTab from '@/components/admin/users/profile/UserWorkspacesTab.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUrlTab } from '@/composables/admin/useUrlTab';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { planName, profileUrl } from '@/lib/admin/users';
import { blank, filled } from '@/lib/helpers';
import { destroy as blockDestroy } from '@/routes/admin/users/block';
import { store as startImpersonation } from '@/routes/admin/users/impersonation';
import type { AdminStat } from '@/types/admin/shared';
import type { AdminAccountBilling, AdminUser, AdminUserActivityData, AdminUserIntegrationsData, AdminUserOverview, AdminUserPostsData, AdminUserTab, AdminUserTabData, AdminUserWorkspacesData } from '@/types/admin/users';

const ALL_TABS: { value: AdminUserTab; label: string }[] = [
    { value: 'overview', label: 'Overview' },
    { value: 'workspaces', label: 'Workspaces and channels' },
    { value: 'posts', label: 'Posts' },
    { value: 'integrations', label: 'Integrations' },
    { value: 'billing', label: 'Billing' },
    { value: 'activity', label: 'Activity' },
];

const props = defineProps<{ user: AdminUser } & AdminUserTabData>();

/**
 * A tab whose request failed or was cancelled goes back to the tab on screen,
 * unless another tab was picked meanwhile.
 */
const restoreTab = (tab: AdminUserTab): void => {
    if (activeTab.value === tab) {
        activeTab.value = props.tab;
    }
};

/** Picking a tab asks the server for that tab's data only. */
const loadTab = (tab: AdminUserTab): void => {
    router.get(
        profileUrl(props.user.id),
        { tab },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['tab', 'data'],
            onError: () => restoreTab(tab),
            onHttpException: () => restoreTab(tab),
            onNetworkError: () => restoreTab(tab),
            onCancel: () => restoreTab(tab),
        },
    );
};

const { tab: activeTab, selectTab } = useUrlTab({
    tabs: ALL_TABS.map((tab) => tab.value),
    initial: props.tab,
    onSelect: loadTab,
});

/** The tab picked while the server has not sent its data yet. */
const isLoading = computed(() => activeTab.value !== props.tab);

/** Each tab's data, once the server sent it for the open tab. */
const overview = computed((): AdminUserOverview | null => {
    if (props.tab === 'overview') {
        return props.data;
    }

    return null;
});

const workspaces = computed((): AdminUserWorkspacesData | null => {
    if (props.tab === 'workspaces') {
        return props.data;
    }

    return null;
});

const posts = computed((): AdminUserPostsData | null => {
    if (props.tab === 'posts') {
        return props.data;
    }

    return null;
});

const integrations = computed((): AdminUserIntegrationsData | null => {
    if (props.tab === 'integrations') {
        return props.data;
    }

    return null;
});

/** The Billing tab of a user whose account the server sent; a user without one has none. */
const billing = computed((): AdminAccountBilling | null => {
    if (props.tab !== 'billing') {
        return null;
    }

    if (props.data.account === null) {
        return null;
    }

    return props.data;
});

const hasNoAccount = computed((): boolean => {
    if (props.tab !== 'billing') {
        return false;
    }

    return blank(props.data.account);
});

const activity = computed((): AdminUserActivityData | null => {
    if (props.tab === 'activity') {
        return props.data;
    }

    return null;
});

const isBlocked = computed(() => filled(props.user.blocked_at));
const canAct = computed(() => !props.user.can_access_admin);

const canViewAs = computed((): boolean => {
    if (props.user.can_access_admin) {
        return false;
    }

    return !isBlocked.value;
});

/** Who the user is at a glance: their plan, their role, when they joined and their email. */
const facts = computed((): AdminStat[] => [
    { key: 'user-plan', label: 'Plan', value: planName(props.user.plan), hint: accessLabel.value },
    { key: 'user-role', label: 'Role', value: roleLabel.value, hint: 'In the account' },
    {
        key: 'user-signed-up',
        label: 'Signed up',
        value: date.formatDate(props.user.created_at),
        hint: date.diffForHumans(props.user.created_at),
    },
    { key: 'user-email', label: 'Email', value: emailLabel.value },
]);

const accessLabel = computed((): string => {
    if (props.user.has_app_access) {
        return 'Has app access';
    }

    return 'No app access';
});

const roleLabel = computed((): string => {
    if (props.user.is_owner) {
        return 'Owner';
    }

    return 'Member';
});

const emailLabel = computed((): string => {
    if (props.user.email_verified) {
        return 'Verified';
    }

    return 'Not verified';
});

const viewingAs = ref(false);
const unblocking = ref(false);
const blockDialogOpen = ref(false);

const viewAs = (): void => {
    router.post(
        startImpersonation.url(props.user.id),
        {},
        {
            onStart: () => {
                viewingAs.value = true;
            },
            onFinish: () => {
                viewingAs.value = false;
            },
        },
    );
};

const openBlockDialog = (): void => {
    blockDialogOpen.value = true;
};

const unblock = (): void => {
    router.delete(blockDestroy.url(props.user.id), {
        preserveScroll: true,
        onStart: () => {
            unblocking.value = true;
        },
        onFinish: () => {
            unblocking.value = false;
        },
    });
};
</script>

<template>
    <Head :title="user.name" />

    <AdminLayout :title="user.name" :description="user.email">
        <template v-if="canAct" #actions>
            <Button
                v-if="canViewAs"
                variant="outline"
                data-testid="admin-impersonate-user"
                :loading="viewingAs"
                @click="viewAs"
            >
                <IconEye class="size-4" />
                View as user
            </Button>
            <Button
                v-if="isBlocked"
                variant="outline"
                data-testid="admin-unblock-user"
                :loading="unblocking"
                @click="unblock"
            >
                <IconLockOpen class="size-4" />
                Unblock
            </Button>
            <Button
                v-else
                variant="destructive"
                data-testid="admin-block-user"
                @click="openBlockDialog"
            >
                <IconLock class="size-4" />
                Block
            </Button>
        </template>

        <div class="flex flex-col gap-8" data-testid="admin-user">
            <div
                v-if="isBlocked"
                class="overflow-hidden rounded-xl border border-destructive/40 bg-destructive/5"
                data-testid="admin-user-blocked"
            >
                <p class="px-5 pt-4 text-sm font-emphasis text-destructive sm:px-6">
                    Blocked since {{ date.formatDateTime(user.blocked_at) }}
                </p>
                <p class="px-5 pt-1 pb-4 text-sm text-muted-foreground sm:px-6">
                    {{ user.blocked_reason }}
                </p>
            </div>

            <div class="overflow-hidden rounded-xl border border-border bg-card" data-testid="admin-user-facts">
                <AdminStatGrid :stats="facts" flush-top />
            </div>

            <Tabs
                :model-value="activeTab"
                class="flex flex-col gap-8"
                @update:model-value="selectTab"
            >
                <TabsList variant="line">
                    <TabsTrigger
                        v-for="tabOption in ALL_TABS"
                        :key="tabOption.value"
                        :value="tabOption.value"
                        :data-testid="`admin-user-tab-${tabOption.value}`"
                    >
                        {{ tabOption.label }}
                    </TabsTrigger>
                </TabsList>
            </Tabs>

            <div
                v-if="isLoading"
                class="flex flex-col gap-4"
                aria-busy="true"
                data-testid="admin-user-tab-loading"
            >
                <Skeleton class="h-28 w-full rounded-xl" />
                <Skeleton class="h-48 w-full rounded-xl" />
                <Skeleton class="h-48 w-full rounded-xl" />
            </div>
            <template v-else>
                <UserOverviewTab v-if="overview" :data="overview" />
                <UserWorkspacesTab v-if="workspaces" :data="workspaces" />
                <UserPostsTab v-if="posts" :data="posts" />
                <UserIntegrationsTab v-if="integrations" :data="integrations" />
                <AdminEmptyState v-if="hasNoAccount" test-id="admin-user-billing-no-account">
                    This user has no account.
                </AdminEmptyState>
                <UserBillingTab v-if="billing" :data="billing" />
                <UserActivityTab v-if="activity" :data="activity" :user-name="user.name" />
            </template>
        </div>
    </AdminLayout>

    <BlockUserDialog
        v-if="canAct"
        v-model:open="blockDialogOpen"
        :user-id="user.id"
        :user-name="user.name"
    />
</template>
