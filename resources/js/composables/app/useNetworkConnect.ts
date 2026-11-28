import { router } from '@inertiajs/vue3';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';

import { useConnectChannelDialog } from '@/composables/app/useConnectChannelDialog';
import { connectEntry, connectUrl } from '@/lib/app/connectRoutes';
import { Platform } from '@/types/app/platform';
import type { AvailablePlatform } from '@/types/app/social-account';

export const useNetworkConnect = (
    platforms: MaybeRefOrGetter<AvailablePlatform[]>,
) => {
    const dialog = useConnectChannelDialog();

    const openConnect = (platform: string, reconnectId?: string) => {
        const url = connectUrl(platform, reconnectId);

        if (url) {
            router.visit(url);
        }
    };

    const startConnect = (platform: string, reconnectId?: string) => {
        const entry = connectEntry(platform);

        if (entry === Platform.Telegram) {
            dialog.openAt('telegram', reconnectId);
            return;
        }

        if (entry === Platform.Instagram && !reconnectId) {
            dialog.openAt('instagram');
            return;
        }

        openConnect(entry, reconnectId);
    };

    const instagramMethods = computed(
        () =>
            toValue(platforms).find(
                (platform) => platform.value === Platform.Instagram,
            )?.connect_methods ?? [
                Platform.Instagram,
                Platform.InstagramFacebook,
            ],
    );

    return { startConnect, openConnect, instagramMethods };
};
