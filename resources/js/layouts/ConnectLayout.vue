<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { IconArrowsLeftRight, IconX } from '@tabler/icons-vue';
import { onMounted, onUnmounted } from 'vue';

import AppLogo from '@/components/app/AppLogo.vue';
import ImpersonationBanner from '@/components/app/ImpersonationBanner.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';

defineProps<{
    title: string;
    platform: string;
    closeUrl: string;
}>();

const clearOAuthFragment = () => {
    if (!window.location.hash.startsWith('#_')) {
        return;
    }

    router.replace({
        url: `${window.location.pathname}${window.location.search}`,
        preserveScroll: true,
        preserveState: true,
    });
};

const stopClearingOnNavigate = router.on('navigate', clearOAuthFragment);

onMounted(clearOAuthFragment);
onUnmounted(stopClearingOnNavigate);
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-dvh flex-col bg-muted" data-testid="connect-page">
        <ImpersonationBanner />
        <div class="flex flex-1 flex-col items-center sm:justify-center sm:px-6 sm:py-10">
            <div
                class="flex w-full flex-1 flex-col bg-card sm:h-[min(45rem,calc(100dvh-5rem))] sm:max-w-[58rem] sm:flex-none sm:rounded-2xl sm:border sm:shadow-sm"
            >
                <header
                    class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 px-4 pt-4 sm:px-6 sm:pt-5"
                    data-testid="connect-header"
                >
                    <div class="flex min-w-0 items-center justify-start">
                        <slot name="header-start">
                            <span
                                class="hidden text-sm whitespace-nowrap text-muted-foreground sm:block"
                                data-testid="connect-header-label"
                            >
                                {{ $t('accounts.connect.label') }}
                            </span>
                        </slot>
                    </div>
                    <div
                        class="col-start-2 flex items-center gap-2"
                        aria-hidden="true"
                        data-testid="connect-header-logos"
                    >
                        <AppLogo variant="mark" class="text-[32px]" />
                        <IconArrowsLeftRight class="size-5 text-muted-foreground" />
                        <PlatformLogo :platform="platform" :size="32" />
                    </div>
                    <div class="col-start-3 flex justify-end">
                        <Button
                            as-child
                            variant="ghost"
                            size="icon"
                            :aria-label="$t('accounts.connect.close')"
                        >
                            <Link :href="closeUrl" data-testid="connect-close">
                                <IconX class="size-4" />
                            </Link>
                        </Button>
                    </div>
                </header>

                <main class="flex flex-1 flex-col px-4 py-6 sm:min-h-0 sm:overflow-y-auto sm:px-6 sm:pt-10">
                    <div class="mx-auto flex w-full max-w-[36rem] flex-col gap-6">
                        <slot />
                    </div>
                </main>

                <footer
                    v-if="$slots.footer"
                    class="sticky bottom-0 flex items-center justify-between gap-3 border-t bg-card px-4 py-3 sm:rounded-b-2xl sm:px-6"
                    data-testid="connect-footer"
                >
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </div>
</template>
