<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconChevronRight } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { type NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
        // Collapsible group: the label becomes the trigger (chevron). Without it, always open.
        collapsible?: boolean;
        // Initial state when collapsible.
        defaultOpen?: boolean;
    }>(),
    {
        collapsible: false,
        defaultOpen: true,
    },
);

const { urlIsActive } = useActiveUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <Collapsible :default-open="collapsible ? defaultOpen : true" class="group/collapsible">
            <SidebarGroupLabel v-if="label" :as-child="collapsible">
                <CollapsibleTrigger v-if="collapsible" class="flex w-full cursor-pointer items-center">
                    {{ label }}
                    <IconChevronRight class="ml-auto size-4 transition-transform group-data-[state=open]/collapsible:rotate-90" />
                </CollapsibleTrigger>
                <span v-else>{{ label }}</span>
            </SidebarGroupLabel>
            <CollapsibleContent>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in items" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="urlIsActive(item.activePattern ?? item.href, { exact: item.exact, exclude: item.excludeActive })"
                            :tooltip="item.title"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                        <Badge
                            v-if="item.badge"
                            variant="warning"
                            class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 px-1.5 group-data-[collapsible=icon]:hidden"
                        >
                            {{ item.badge }}
                        </Badge>
                    </SidebarMenuItem>
                </SidebarMenu>
            </CollapsibleContent>
        </Collapsible>
    </SidebarGroup>
</template>
