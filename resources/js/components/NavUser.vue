<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconChevronRight, IconSelector } from '@tabler/icons-vue';
import { computed } from 'vue';

import NotificationBell from '@/components/NotificationBell.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import UserInfo from '@/components/UserInfo.vue';

import UserMenuContent from './UserMenuContent.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const currentWorkspace = computed(() => page.props.auth.currentWorkspace);
const { isMobile } = useSidebar();
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <div class="flex items-center gap-1">
                <NotificationBell v-if="currentWorkspace" />
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="sidebar-menu-button"
                    >
                        <UserInfo :user="user" />
                        <component :is="isMobile ? IconSelector : IconChevronRight" class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56"
                    :side="isMobile ? 'bottom' : 'right'"
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
            </div>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
