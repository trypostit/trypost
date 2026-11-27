<script setup lang="ts">
import { computed, type Component } from 'vue';

import AdminSidebar from '@/components/admin/AdminSidebar.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import { useActiveUrl } from '@/composables/useActiveUrl';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import { ADMIN_NAV_GROUPS } from '@/lib/adminNavigation';
import { filled } from '@/lib/helpers';

/**
 * The backoffice page: the app's shell with the admin sidebar in place of the
 * app's, like `SettingsLayout` swaps in the settings one, and the wide
 * settings page header (the sidebar item's icon, title, description,
 * actions).
 */
defineProps<{
    title: string;
    description?: string;
}>();

const { urlIsActive } = useActiveUrl();

/** The page takes the icon of the sidebar item it sits under, like the app's pages. */
const icon = computed((): Component | undefined => {
    const active = ADMIN_NAV_GROUPS.flatMap((group) => group.items).find((item) =>
        urlIsActive(item.href, { exact: item.exact, exclude: item.exclude }),
    );

    if (filled(active)) {
        return active.icon;
    }

    return undefined;
});
</script>

<template>
    <AppSidebarLayout full-width>
        <template #sidebar>
            <AdminSidebar />
        </template>

        <div
            class="mx-auto flex w-full max-w-[1120px] flex-col gap-8 px-4 pt-2 pb-16 md:px-8 md:pt-10"
            data-testid="admin-page"
        >
            <header class="flex items-start justify-between gap-3">
                <HeaderTitle
                    :title="title"
                    :icon="icon"
                    :description="description"
                />
                <div
                    v-if="$slots['actions']"
                    class="flex shrink-0 items-center gap-2"
                >
                    <slot name="actions" />
                </div>
            </header>

            <slot />
        </div>
    </AppSidebarLayout>
</template>
