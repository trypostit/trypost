<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';

import { index as postsIndex } from '@/actions/App/Http/Controllers/App/PostController';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { useSettingsNavigation } from '@/composables/useSettingsNavigation';

const groups = useSettingsNavigation();
const { urlIsActive } = useActiveUrl();

const exactMatchItems = ['general', 'profile', 'account'];
</script>

<template>
    <Sidebar collapsible="offcanvas" data-testid="settings-sidebar">
        <SidebarHeader class="px-6 pt-6 pb-0">
            <Link
                :href="postsIndex.url()"
                class="group/back -mx-2 -my-1.5 flex h-9 items-center gap-2 rounded-lg px-2 text-sidebar-foreground outline-hidden transition-control hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring active:translate-y-px"
                data-testid="settings-back"
            >
                <span
                    class="flex size-6 items-center justify-center rounded-md text-muted-foreground transition-transform duration-150 ease-out group-hover/back:-translate-x-0.5 group-hover/back:text-foreground motion-reduce:transition-none"
                >
                    <IconArrowLeft class="size-4" />
                </span>
                <h3 class="text-base leading-5 font-semibold">
                    {{ $t('settings.sidebar.back') }}
                </h3>
            </Link>
        </SidebarHeader>

        <SidebarContent class="gap-6 px-3 pt-6 pb-4">
            <SidebarGroup
                v-for="group in groups"
                :key="group.key"
                class="p-0"
            >
                <SidebarGroupLabel class="pl-3">{{ group.label }}</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in group.items" :key="item.name">
                        <SidebarMenuButton
                            as-child
                            class="ps-2 pe-3 font-medium text-muted-foreground data-[active=true]:text-sidebar-foreground data-[active=true]:[&>svg]:text-sidebar-foreground"
                            :is-active="urlIsActive(item.href, { exact: exactMatchItems.includes(item.name) })"
                            :tooltip="item.title"
                        >
                            <Link
                                :href="item.href"
                                :data-testid="`settings-nav-${item.name.replaceAll('_', '-')}`"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>
    </Sidebar>
</template>
