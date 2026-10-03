<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { Badge } from '@/components/ui/badge';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { type NavItem } from '@/types';

defineProps<{
    items: NavItem[];
    label?: string;
}>();

const { urlIsActive } = useActiveUrl();
</script>

<template>
    <SidebarGroup class="px-4 py-0 group-data-[collapsible=icon]:px-2.5">
        <SidebarGroupLabel v-if="label">
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="urlIsActive(item.activePattern ?? item.href, { exact: item.exact, exclude: item.excludeActive })"
                    :tooltip="item.title"
                >
                    <Link :href="item.href" :data-testid="`nav-${item.href}`">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge
                    v-if="item.count !== undefined"
                    :data-testid="item.countTestId"
                >
                    {{ item.count }}
                </SidebarMenuBadge>
                <Badge
                    v-else-if="item.badge"
                    variant="warning"
                    class="pointer-events-none absolute top-1/2 end-2 -translate-y-1/2 group-data-[collapsible=icon]:hidden"
                >
                    {{ item.badge }}
                </Badge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
