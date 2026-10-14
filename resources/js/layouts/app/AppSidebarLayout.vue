<script lang="ts">
let compactSidebarOpen: boolean | null = null;
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

import AppHeader from '@/components/AppHeader.vue';
import AppLogo from '@/components/AppLogo.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import ChangelogDialog from '@/components/changelog/ChangelogDialog.vue';
import ConnectChannelDialog from '@/components/channels/ConnectChannelDialog.vue';
import CommandPalette from '@/components/command-palette/CommandPalette.vue';
import GlobalPostComposer from '@/components/posts/composer/GlobalPostComposer.vue';
import ReferralSourceSurvey from '@/components/ReferralSourceSurvey.vue';
import Toast from '@/components/Toast.vue';
import {
    SidebarInset,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { SIDEBAR_COOKIE_NAME } from '@/components/ui/sidebar/utils';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { index as postsIndex } from '@/routes/app/posts';

type Props = {
    fullWidth?: boolean;
};

withDefaults(defineProps<Props>(), {
    fullWidth: false,
});

const readSidebarOpen = (): boolean => {
    if (typeof document === 'undefined') {
        return true;
    }

    return !document.cookie.split('; ').includes(`${SIDEBAR_COOKIE_NAME}=false`);
};

const isCompactViewport = useBelowBreakpoint('lg');
const sidebarOpen = ref(
    isCompactViewport.value
        ? (compactSidebarOpen ?? false)
        : readSidebarOpen(),
);

watch(sidebarOpen, (isOpen) => {
    if (isCompactViewport.value) {
        compactSidebarOpen = isOpen;
    }
});

watch(isCompactViewport, (isCompact) => {
    compactSidebarOpen = null;
    sidebarOpen.value = !isCompact && readSidebarOpen();
});
</script>

<template>
    <SidebarProvider v-model:open="sidebarOpen" class="bg-sidebar">
        <slot name="sidebar">
            <AppSidebar />
        </slot>
        <SidebarInset
            class="min-w-0 overflow-hidden max-md:bg-sidebar md:my-2 md:me-2 md:rounded-xl md:border md:border-border md:bg-card"
            data-testid="app-content-shell"
        >
            <div
                class="flex h-14 shrink-0 items-center gap-2 px-4 md:hidden"
                data-testid="app-mobile-bar"
            >
                <SidebarTrigger
                    class="-ms-1.5 size-9 [&_svg]:size-5"
                    data-testid="app-sidebar-trigger"
                />
                <Link
                    :href="postsIndex.url()"
                    class="flex h-8 items-center rounded-md outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                    data-testid="app-mobile-logo"
                >
                    <AppLogo class="text-[19px]" />
                </Link>
            </div>
            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-card max-md:mx-2 max-md:rounded-t-xl max-md:border max-md:border-b-0 max-md:border-border"
            >
                <AppHeader v-if="$slots['header'] || $slots['header-actions']">
                    <template v-if="$slots['header']" #left>
                        <slot name="header" />
                    </template>
                    <template v-if="$slots['header-actions']" #right>
                        <slot name="header-actions" />
                    </template>
                </AppHeader>
                <div
                    data-testid="app-layout-scroller"
                    :class="
                        fullWidth
                            ? 'flex min-h-0 min-w-0 flex-1 flex-col overflow-x-hidden overflow-y-auto'
                            : 'flex-1 overflow-y-auto'
                    "
                >
                    <div
                        data-testid="app-layout-content"
                        :class="
                            fullWidth
                                ? 'flex min-h-0 min-w-0 flex-1 flex-col'
                                : 'mx-auto w-full max-w-7xl'
                        "
                    >
                        <slot />
                    </div>
                </div>
            </div>
        </SidebarInset>
    </SidebarProvider>
    <GlobalPostComposer />
    <ConnectChannelDialog />
    <ChangelogDialog />
    <CommandPalette />
    <ReferralSourceSurvey />
    <Toast />
</template>
