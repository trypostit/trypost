<script setup lang="ts">
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import SettingsSearch from '@/components/settings/SettingsSearch.vue';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableLoadMore,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUrlTab } from '@/composables/admin/useUrlTab';
import date from '@/date';
import debounce from '@/debounce';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { blank, filled } from '@/lib/helpers';
import { index as usersIndex, show as userShow } from '@/routes/admin/users';
import type { AdminUser, ScrollPage } from '@/types/admin';

type StatusFilter = 'all' | 'blocked';

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
    { value: 'all', label: 'All users' },
    { value: 'blocked', label: 'Blocked' },
];

const props = defineProps<{
    users: ScrollPage<AdminUser>;
    filters: { search: string; status: StatusFilter };
}>();

const searchQuery = ref(props.filters.search);

/** The filters as the URL carries them; an empty search leaves the URL. */
const filterQuery = (): { status: StatusFilter; search?: string } => {
    if (filled(searchQuery.value)) {
        return { status: status.value, search: searchQuery.value };
    }

    return { status: status.value };
};

const reload = (): void => {
    router.get(usersIndex.url(), filterQuery(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['users', 'filters'],
        reset: ['users'],
    });
};

const { tab: status, selectTab: selectStatus } = useUrlTab({
    tabs: STATUS_FILTERS.map((filter) => filter.value),
    initial: props.filters.status,
    param: 'status',
    onSelect: reload,
});

const search = debounce(reload, 300);

watch(searchQuery, () => search());

const hasNoResults = computed(() => blank(props.users.data));

const planLabel = (user: AdminUser): string => {
    if (filled(user.plan)) {
        return user.plan;
    }

    return 'No plan';
};
</script>

<template>
    <Head title="Users" />

    <AdminLayout
        title="Users"
        description="Everyone who signed up, newest first."
    >
        <Tabs
            :model-value="status"
            class="flex flex-col gap-6"
            @update:model-value="selectStatus"
        >
            <TabsList variant="line">
                <TabsTrigger
                    v-for="filter in STATUS_FILTERS"
                    :key="filter.value"
                    :value="filter.value"
                    :data-testid="`admin-users-filter-${filter.value}`"
                >
                    {{ filter.label }}
                </TabsTrigger>
            </TabsList>
        </Tabs>

        <div class="flex flex-col gap-3">
            <SettingsSearch
                v-model="searchQuery"
                placeholder="Search by name or email"
            />

            <AdminEmptyState v-if="hasNoResults" variant="page" test-id="admin-users-empty">
                No users match this search.
            </AdminEmptyState>

            <div
                v-else
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <InfiniteScroll
                    data="users"
                    items-element="#admin-users-body"
                    preserve-url
                >
                    <div class="overflow-x-auto">
                        <Table data-testid="admin-users">
                            <TableHeader>
                                <TableRow class="hover:bg-transparent">
                                    <TableHead class="h-11 border-r-0 px-4 sm:px-5">
                                        User
                                    </TableHead>
                                    <TableHead class="h-11 border-r-0 px-4">
                                        Plan
                                    </TableHead>
                                    <TableHead class="h-11 border-r-0 px-4">
                                        Workspaces
                                    </TableHead>
                                    <TableHead class="h-11 border-r-0 px-4">
                                        Signed up
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody id="admin-users-body">
                                <TableRow
                                    v-for="user in users.data"
                                    :key="user.id"
                                    class="last:border-b-0"
                                    :data-testid="`admin-user-${user.id}`"
                                >
                                    <TableCell class="border-r-0 px-4 py-3 sm:px-5">
                                        <Link
                                            :href="userShow.url(user.id)"
                                            class="flex min-w-0 items-center gap-3 rounded-lg outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                            :data-testid="`admin-user-link-${user.id}`"
                                        >
                                            <Avatar
                                                :src="user.photo_url"
                                                :name="user.name"
                                                class="size-8 shrink-0 rounded-lg"
                                                fallback-class="bg-primary-subtle text-primary-text text-xs font-medium"
                                            />
                                            <span class="grid min-w-0">
                                                <span
                                                    class="truncate text-sm font-emphasis text-foreground hover:underline"
                                                    >{{ user.name }}</span
                                                >
                                                <span
                                                    class="truncate text-xs text-muted-foreground"
                                                    >{{ user.email }}</span
                                                >
                                            </span>
                                        </Link>
                                    </TableCell>
                                    <TableCell class="border-r-0 px-4 py-3">
                                        <Badge
                                            v-if="user.blocked_at"
                                            variant="destructive"
                                            :data-testid="`admin-user-blocked-${user.id}`"
                                            >Blocked</Badge
                                        >
                                        <template v-else>
                                            <Badge
                                                v-if="user.has_app_access"
                                                variant="success"
                                                >{{ planLabel(user) }}</Badge
                                            >
                                            <Badge v-else variant="secondary">{{
                                                planLabel(user)
                                            }}</Badge>
                                        </template>
                                    </TableCell>
                                    <TableCell
                                        class="border-r-0 px-4 py-3 tabular-nums"
                                    >
                                        {{ user.workspaces_count }}
                                    </TableCell>
                                    <TableCell
                                        class="border-r-0 px-4 py-3 text-muted-foreground tabular-nums"
                                    >
                                        {{ date.formatDateTime(user.created_at) }}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <template #next="{ loading }">
                        <TableLoadMore v-if="loading" />
                    </template>
                </InfiniteScroll>
            </div>
        </div>
    </AdminLayout>
</template>
