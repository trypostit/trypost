<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

import AppHeader from '@/components/AppHeader.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import Toast from '@/components/Toast.vue';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { heartbeat as heartbeatRoute } from '@/routes/app/presence';

const page = usePage();
const isOpen = page.props.sidebarOpen;

type Props = {
    fullWidth?: boolean;
};

withDefaults(defineProps<Props>(), {
    fullWidth: false,
});

const heartbeatHttp = useHttp<Record<string, never>, { ok: boolean }>({});

let heartbeatTimer: ReturnType<typeof setInterval> | null = null;

const sendHeartbeat = () => {
    if (typeof document === 'undefined' || document.hidden) return;
    void heartbeatHttp.post(heartbeatRoute.url()).catch(() => undefined);
};

onMounted(() => {
    sendHeartbeat();
    heartbeatTimer = setInterval(sendHeartbeat, 30_000);
});

onBeforeUnmount(() => {
    if (heartbeatTimer) clearInterval(heartbeatTimer);
});
</script>

<template>
    <SidebarProvider :default-open="isOpen">
        <AppSidebar />
        <SidebarInset class="overflow-x-hidden">
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
                class="absolute left-4 top-3 z-30 size-10 rounded-md border-2 border-foreground bg-card text-foreground shadow-2xs md:hidden"
            />
            <div
                :class="
                    fullWidth
                        ? 'flex min-h-0 flex-1 flex-col overflow-y-auto'
                        : 'flex-1 overflow-y-auto'
                "
            >
                <div
                    :class="[
                        fullWidth
                            ? 'flex min-h-0 flex-1 flex-col'
                            : 'mx-auto w-full max-w-7xl',
                        !fullWidth && !$slots['header'] && !$slots['header-actions']
                            ? 'pt-14 md:pt-0'
                            : '',
                    ]"
                >
                    <slot />
                </div>
            </div>
        </SidebarInset>
    </SidebarProvider>
    <Toast />
</template>
