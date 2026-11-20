<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast } from 'vue-sonner';

import { Toaster } from '@/components/ui/sonner';

/** A link a toast offers next to its message: "View post" and where it goes. */
type FlashLink = {
    label: string;
    url: string;
};

type Flash = {
    banner?: string;
    bannerStyle?: 'success' | 'danger' | 'warning' | 'info';
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
    link?: FlashLink;
};

const page = usePage();

/**
 * What a toast adds when the flash carries a link: the link right after the
 * message, in a toast as wide as both. Any flashed message can lead somewhere.
 */
const linkOptions = (link: FlashLink | undefined) => {
    if (!link) {
        return {};
    }

    return {
        action: {
            label: link.label,
            onClick: () => router.visit(link.url),
        },
        classes: {
            toast: '!inset-x-0 !mx-auto !w-fit !max-w-(--width)',
            content: '!grow-0',
        },
    };
};

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

        const link = linkOptions(flash.link);

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
