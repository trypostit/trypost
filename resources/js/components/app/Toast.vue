<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';

import { Toaster } from '@/components/ui/sonner';
import { type ToastLink, toastLinkOptions } from '@/lib/app/toastLink';

type Flash = {
    banner?: string;
    bannerStyle?: 'success' | 'danger' | 'warning' | 'info';
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
    link?: ToastLink;
};

const page = usePage();

watch(
    () => page.props.flash as Flash | undefined,
    (flash) => {
        if (!flash) {
            return;
        }

        if (flash.banner) {
            switch (flash.bannerStyle) {
                case 'danger':
                    toast.error(flash.banner, { id: 'flash-banner', testId: 'flash-banner-toast' });
                    break;
                case 'warning':
                    toast.warning(flash.banner, { id: 'flash-banner', testId: 'flash-banner-toast' });
                    break;
                case 'info':
                    toast.info(flash.banner, { id: 'flash-banner', testId: 'flash-banner-toast' });
                    break;
                case 'success':
                default:
                    toast.success(flash.banner, { id: 'flash-banner', testId: 'flash-banner-toast' });
            }
        }

        const link = toastLinkOptions(flash.link, (url) => router.visit(url));

        if (flash.success) {
            toast.success(flash.success, { id: 'flash-success', testId: 'flash-success-toast', ...link });
        }

        if (flash.error) {
            toast.error(flash.error, { id: 'flash-error', testId: 'flash-error-toast', ...link });
        }

        if (flash.warning) {
            toast.warning(flash.warning, { id: 'flash-warning', testId: 'flash-warning-toast', ...link });
        }

        if (flash.info) {
            toast.info(flash.info, { id: 'flash-info', testId: 'flash-info-toast', ...link });
        }
    },
    { deep: true, immediate: true },
);
</script>

<template>
    <Toaster />
</template>
