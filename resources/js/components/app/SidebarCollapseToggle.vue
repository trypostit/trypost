<script setup lang="ts">
import {
    IconLayoutSidebarLeftCollapse,
    IconLayoutSidebarLeftExpand,
} from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

const { isMobile, state: sidebarState, toggleSidebar } = useSidebar();
</script>

<template>
    <Tooltip v-if="!isMobile">
        <TooltipTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-8 shrink-0 text-muted-foreground"
                :aria-label="
                    sidebarState === 'collapsed'
                        ? $t('sidebar.expand')
                        : $t('sidebar.collapse')
                "
                data-testid="sidebar-footer-toggle"
                @click="toggleSidebar"
            >
                <IconLayoutSidebarLeftExpand
                    v-if="sidebarState === 'collapsed'"
                    class="size-4"
                />
                <IconLayoutSidebarLeftCollapse
                    v-else
                    class="size-4"
                />
            </Button>
        </TooltipTrigger>
        <TooltipContent :side="sidebarState === 'collapsed' ? 'right' : 'top'">
            {{
                sidebarState === 'collapsed'
                    ? $t('sidebar.expand')
                    : $t('sidebar.collapse')
            }}
        </TooltipContent>
    </Tooltip>
</template>
