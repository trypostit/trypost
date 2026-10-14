<script setup lang="ts">
import {
    IconArrowLeft,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelPlatformGrid from '@/components/channels/ChannelPlatformGrid.vue';
import ConnectChannelDetails from '@/components/channels/ConnectChannelDetails.vue';
import InstagramConnectStep from '@/components/channels/InstagramConnectStep.vue';
import InstagramFacebookRequirementsStep from '@/components/channels/InstagramFacebookRequirementsStep.vue';
import TelegramConnectStep from '@/components/channels/TelegramConnectStep.vue';
import { Button } from '@/components/ui/button';
import { DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    useConnectChannelDialog,
    type ConnectChannelState,
} from '@/composables/useConnectChannelDialog';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { Platform } from '@/types/platform';
import type { AvailablePlatform } from '@/types/social-account';

const props = defineProps<{
    state: ConnectChannelState;
    platforms: AvailablePlatform[];
}>();

const emit = defineEmits<{
    close: [];
    connected: [{ accountId: string | null; created: boolean }];
}>();

const { goTo, back, showDetails } = useConnectChannelDialog();
const { openConnect, instagramMethods } = useNetworkConnect(
    computed(() => props.platforms),
);

const detailsPlatform = computed(() =>
    props.state.step === 'details'
        ? props.platforms.find(
              (platform) => platform.value === props.state.detailsPlatform,
          )
        : undefined,
);

const selectPlatform = (platform: string): void => {
    if (platform === Platform.Instagram) {
        goTo('instagram');
        return;
    }

    if (platform === Platform.Telegram) {
        goTo('telegram');
        return;
    }

    emit('close');
    openConnect(platform);
};

const connectInstagramFacebook = (): void => {
    emit('close');
    openConnect(Platform.InstagramFacebook, props.state.reconnectId);
};

const selectInstagramMethod = (method: string): void => {
    emit('close');
    openConnect(method, props.state.reconnectId);
};
</script>

<template>
    <div
        :key="state.step"
        class="motion-view-swap relative flex min-h-0 min-w-0 flex-1 flex-col"
    >
        <ConnectChannelDetails
            v-if="detailsPlatform"
            :platform="detailsPlatform"
            :platforms="platforms"
            @back="back"
            @connect="selectPlatform"
        />

        <div
            v-else-if="state.step === 'grid'"
            class="flex min-h-0 flex-1 flex-col bg-muted pt-4 sm:rounded-lg"
        >
            <DialogHeader
                class="min-h-8 shrink-0 justify-center px-14 pb-6 sm:text-center"
            >
                <DialogTitle
                    class="font-sans text-xl leading-tight font-medium"
                >
                    {{ $t('channels.dialog.title') }}
                </DialogTitle>
            </DialogHeader>
            <div class="min-h-0 flex-1 overflow-y-auto">
                <ChannelPlatformGrid
                    :platforms="platforms"
                    with-details
                    @select="selectPlatform"
                    @details="showDetails"
                />
            </div>
        </div>

        <template v-else>
            <Button
                v-if="state.canGoBack"
                variant="ghost"
                size="icon"
                class="absolute start-4 top-4 z-10"
                :aria-label="$t('channels.dialog.back')"
                data-testid="connect-channel-back"
                @click="back"
            >
                <IconArrowLeft class="size-4 rtl:rotate-180" />
            </Button>
            <InstagramConnectStep
                v-if="state.step === 'instagram'"
                :methods="instagramMethods"
                @select="selectInstagramMethod"
            />
            <InstagramFacebookRequirementsStep
                v-else-if="state.step === 'instagram-facebook'"
                @connect="connectInstagramFacebook"
            />
            <TelegramConnectStep
                v-else-if="state.step === 'telegram'"
                :reconnect-id="state.reconnectId"
                @connected="(result) => emit('connected', result)"
            />
        </template>
    </div>
</template>
