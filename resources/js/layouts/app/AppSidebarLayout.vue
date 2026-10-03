<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import ConnectChannelDialog from '@/components/channels/ConnectChannelDialog.vue';
import CommandPalette from '@/components/command-palette/CommandPalette.vue';
import GlobalPostComposer from '@/components/posts/composer/GlobalPostComposer.vue';
import Toast from '@/components/Toast.vue';
import {
    SidebarInset,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';

type Props = {
    fullWidth?: boolean;
};

withDefaults(defineProps<Props>(), {
    fullWidth: false,
});
</script>

<template>
    <SidebarProvider :open="true" class="bg-sidebar">
        <slot name="sidebar">
            <AppSidebar />
        </slot>
        <SidebarInset
            class="overflow-hidden bg-card md:my-2 md:me-2 md:rounded-xl md:border md:border-border"
            data-testid="app-content-shell"
        >
            <AppHeader v-if="$slots['header'] || $slots['header-actions']">
                <template v-if="$slots['header']" #left>
                    <slot name="header" />
                </template>
                <template v-if="$slots['header-actions']" #right>
                    <slot name="header-actions" />
                </template>
            </AppHeader>
            <SidebarTrigger
                v-else
                data-testid="app-sidebar-trigger"
                class="absolute top-3 left-4 z-30 size-8 rounded-lg border border-border-strong bg-card text-foreground md:hidden md:group-has-data-[collapsible=offcanvas]/sidebar-wrapper:inline-flex"
            />
            <div
                data-testid="app-layout-scroller"
                :class="
                    fullWidth
                        ? 'flex min-h-0 flex-1 flex-col overflow-y-auto'
                        : 'flex-1 overflow-y-auto'
                "
            >
                <div
                    data-testid="app-layout-content"
                    :class="[
                        fullWidth
                            ? 'flex min-h-0 flex-1 flex-col'
                            : 'mx-auto w-full max-w-7xl',
                        !$slots['header'] && !$slots['header-actions']
                            ? 'pt-14 md:pt-0'
                            : '',
                    ]"
                >
                    <slot />
                </div>
            </div>
        </SidebarInset>
    </SidebarProvider>
    <GlobalPostComposer />
    <ConnectChannelDialog />
    <CommandPalette />
    <Toast />
</template>
