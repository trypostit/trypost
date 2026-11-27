<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';

import { index as postsIndex } from '@/actions/App/Http/Controllers/App/PostController';
import BuildVersion from '@/components/settings/BuildVersion.vue';
import SidebarUserMenu from '@/components/SidebarUserMenu.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { ADMIN_NAV_GROUPS } from '@/lib/adminNavigation';

const { urlIsActive } = useActiveUrl();
const { isMobile, state: sidebarState } = useSidebar();
</script>

<template>
    <Sidebar collapsible="icon" data-testid="admin-sidebar">
        <SidebarHeader
            class="px-6 pt-6 pb-0 group-data-[collapsible=icon]:px-2.5 group-data-[collapsible=icon]:pt-4"
        >
            <Tooltip>
                <TooltipTrigger as-child>
                    <Link
                        :href="postsIndex.url()"
                        class="group/back -mx-2 -my-1.5 flex h-9 items-center gap-2 rounded-lg px-2 text-sidebar-foreground outline-hidden transition-control hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring active:translate-y-px group-data-[collapsible=icon]:m-0 group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0"
                        data-testid="admin-back-to-app"
                    >
                        <span
                            class="flex size-6 items-center justify-center rounded-md text-muted-foreground transition-transform duration-150 ease-out group-hover/back:-translate-x-0.5 group-hover/back:text-foreground motion-reduce:transition-none"
                        >
                            <IconArrowLeft class="size-4" />
                        </span>
                        <h3
                            class="text-base leading-5 font-semibold group-data-[collapsible=icon]:sr-only"
                        >
                            Backoffice
                        </h3>
                    </Link>
                </TooltipTrigger>
                <TooltipContent
                    side="right"
                    align="center"
                    :hidden="sidebarState !== 'collapsed' || isMobile"
                >
                    Back to app
                </TooltipContent>
            </Tooltip>
        </SidebarHeader>
        <SidebarContent
            class="gap-6 px-3 pt-6 pb-4 group-data-[collapsible=icon]:gap-4 group-data-[collapsible=icon]:px-2.5"
        >
            <SidebarGroup v-for="group in ADMIN_NAV_GROUPS" :key="group.key" class="p-0">
                <SidebarGroupLabel
                    class="pl-3 group-data-[collapsible=icon]:hidden"
                    >{{ group.label }}</SidebarGroupLabel
                >
                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="item in group.items"
                        :key="item.name"
                    >
                        <SidebarMenuButton
                            as-child
                            class="ps-2 pe-3 font-medium text-muted-foreground data-[active=true]:text-sidebar-foreground data-[active=true]:[&>svg]:text-sidebar-foreground"
                            :is-active="urlIsActive(item.href, { exact: item.exact, exclude: item.exclude })"
                            :tooltip="item.title"
                        >
                            <Link
                                :href="item.href"
                                :data-testid="`admin-nav-${item.name}`"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
            <BuildVersion />
        </SidebarContent>
        <SidebarFooter class="gap-0 p-0">
            <SidebarUserMenu />
        </SidebarFooter>
    </Sidebar>
</template>
