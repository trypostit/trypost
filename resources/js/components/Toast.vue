<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';

import { Toaster } from '@/components/ui/sonner';

type Flash = {
    banner?: string;
    bannerStyle?: 'success' | 'danger' | 'warning' | 'info';
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
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
                    toast.error(flash.banner, { id: 'flash-banner' });
                    break;
                case 'warning':
                    toast.warning(flash.banner, { id: 'flash-banner' });
                    break;
                case 'info':
                    toast.info(flash.banner, { id: 'flash-banner' });
                    break;
                case 'success':
                default:
                    toast.success(flash.banner, { id: 'flash-banner' });
            }
        }

        if (flash.success) {
            toast.success(flash.success, { id: 'flash-success' });
        }

        if (flash.error) {
            toast.error(flash.error, { id: 'flash-error' });
        }

        if (flash.warning) {
            toast.warning(flash.warning, { id: 'flash-warning' });
        }

        if (flash.info) {
            toast.info(flash.info, { id: 'flash-info' });
        }
    },
    { deep: true, immediate: true },
);
</script>

<template>
    <Toaster />
</template>
