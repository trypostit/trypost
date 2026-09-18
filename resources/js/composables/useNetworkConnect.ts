import { router } from '@inertiajs/vue3';
import { computed, ref, toValue, type MaybeRefOrGetter } from 'vue';
import { toast } from 'vue-sonner';

import { oauthConnectUrl, useOAuthPopup } from '@/composables/useOAuthPopup';
import { Platform } from '@/types/platform';
import type { AvailablePlatform } from '@/types/social-account';

export const useNetworkConnect = (
    platforms: MaybeRefOrGetter<AvailablePlatform[]>,
) => {
    const telegramOpen = ref(false);
    const telegramReconnectId = ref<string>();
    const instagramOpen = ref(false);

    const { openOAuthPopup } = useOAuthPopup((result) => {
        if (result.success) {
            toast.success(result.message);
            router.reload();
            return;
        }

        toast.error(result.message);
    });

    const connectEntry = (platform: string): string =>
        platform === Platform.LinkedInPage ? Platform.LinkedIn : platform;

    const openConnect = (platform: string, reconnectId?: string) => {
        const url = oauthConnectUrl(platform, reconnectId);

        if (url) {
            openOAuthPopup(url);
        }
    };

    const startConnect = (platform: string, reconnectId?: string) => {
        const entry = connectEntry(platform);

        if (entry === Platform.Telegram) {
            telegramReconnectId.value = reconnectId;
            telegramOpen.value = true;
            return;
        }

        if (entry === Platform.Instagram && !reconnectId) {
            instagramOpen.value = true;
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

    return {
        telegramOpen,
        telegramReconnectId,
        instagramOpen,
        instagramMethods,
        startConnect,
        openConnect,
    };
};
