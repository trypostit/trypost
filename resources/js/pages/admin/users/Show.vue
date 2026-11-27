<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { IconEye, IconLock, IconLockOpen } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import AdminStatGrid, { type AdminStat } from '@/components/admin/AdminStatGrid.vue';
import BlockUserDialog from '@/components/admin/BlockUserDialog.vue';
import UserActivityTab from '@/components/admin/user/UserActivityTab.vue';
import UserBillingTab from '@/components/admin/user/UserBillingTab.vue';
import UserIntegrationsTab from '@/components/admin/user/UserIntegrationsTab.vue';
import UserOverviewTab from '@/components/admin/user/UserOverviewTab.vue';
import UserPostsTab from '@/components/admin/user/UserPostsTab.vue';
import UserWorkspacesTab from '@/components/admin/user/UserWorkspacesTab.vue';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import date from '@/date';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { filled } from '@/lib/helpers';
import { impersonate, show as userShow } from '@/routes/admin/users';
import { destroy as blockDestroy } from '@/routes/admin/users/block';
import type {
    AdminUser,
    AdminUserActivityData,
    AdminUserBillingData,
    AdminUserIntegrationsData,
    AdminUserOverview,
    AdminUserPostsData,
    AdminUserTab,
    AdminUserWorkspacesData,
} from '@/types/admin';

const ALL_TABS: { value: AdminUserTab; label: string }[] = [
    { value: 'overview', label: 'Overview' },
    { value: 'workspaces', label: 'Workspaces and channels' },
    { value: 'posts', label: 'Posts' },
    { value: 'integrations', label: 'Integrations' },
    { value: 'billing', label: 'Billing' },
    { value: 'activity', label: 'Activity' },
];

const props = defineProps<{
    user: AdminUser;
    tab: AdminUserTab;
    data: unknown;
}>();

const page = usePage();



const userTab = (value: unknown): AdminUserTab =>
    ALL_TABS.find((tab) => tab.value === value)?.value ?? 'overview';

/** The open tab is read from the URL, like the Billing tabs. */
const activeTab = ref<AdminUserTab>(
    userTab(new URL(page.url, window.location.origin).searchParams.get('tab')),
);

const selectTab = (value: string | number): void => {
    activeTab.value = userTab(value);

    router.get(
        userShow.url(props.user.id),
        { tab: activeTab.value },
        { preserveScroll: true, preserveState: true, replace: true, only: ['tab', 'data'] },
    );
};

/** The open tab's data, once the server sent it for that tab. */
const isLoaded = (tab: AdminUserTab): boolean => props.tab === tab && activeTab.value === tab;

const overview = computed(() => props.data as AdminUserOverview);
const workspaces = computed(() => props.data as AdminUserWorkspacesData);
const posts = computed(() => props.data as AdminUserPostsData);
const integrations = computed(() => props.data as AdminUserIntegrationsData);
const billing = computed(() => props.data as AdminUserBillingData);
const activity = computed(() => props.data as AdminUserActivityData);

const isBlocked = computed(() => filled(props.user.blocked_at));
const canAct = computed(() => !props.user.is_backoffice_admin);

const canViewAs = computed((): boolean => {
    if (props.user.is_backoffice_admin) {
        return false;
    }

    return !isBlocked.value;
});

const planLabel = computed((): string => {
    if (filled(props.user.plan)) {
        return props.user.plan;
    }

    return 'No plan';
});

/** Who the user is at a glance: their plan, their role, when they joined and their email. */
const facts = computed((): AdminStat[] => {
    const stats: AdminStat[] = [];

    stats.push(
        { key: 'user-plan', label: 'Plan', value: planLabel.value, hint: accessLabel.value },
        { key: 'user-role', label: 'Role', value: roleLabel.value, hint: 'In the account' },
        {
            key: 'user-signed-up',
            label: 'Signed up',
            value: date.formatDayMonthYear(props.user.created_at),
            hint: date.diffForHumans(props.user.created_at),
        },
        { key: 'user-email', label: 'Email', value: emailLabel.value },
    );

    return stats;
});

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

const blockedSince = computed((): string => {
    if (filled(props.user.blocked_at)) {
        return date.formatDateTime(props.user.blocked_at);
    }

    return '';
});

const viewingAs = ref(false);
const unblocking = ref(false);
const blockDialogOpen = ref(false);

const viewAs = (): void => {
    router.post(
        impersonate.url(props.user.id),
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
                    Blocked since {{ blockedSince }}
                </p>
                <p class="px-5 pt-1 pb-4 text-sm text-muted-foreground sm:px-6">
                    {{ user.blocked_reason }}
                </p>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-border bg-card [&>div]:border-t-0"
                data-testid="admin-user-facts"
            >
                <AdminStatGrid :stats="facts" />
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

            <UserOverviewTab v-if="isLoaded('overview')" :data="overview" />
            <UserWorkspacesTab v-if="isLoaded('workspaces')" :data="workspaces" />
            <UserPostsTab v-if="isLoaded('posts')" :data="posts" />
            <UserIntegrationsTab v-if="isLoaded('integrations')" :data="integrations" />
            <UserBillingTab v-if="isLoaded('billing')" :data="billing" />
            <UserActivityTab v-if="isLoaded('activity')" :data="activity" :user-name="user.name" />
        </div>
    </AdminLayout>

    <BlockUserDialog
        v-if="canAct"
        v-model:open="blockDialogOpen"
        :user-id="user.id"
        :user-name="user.name"
    />
</template>
