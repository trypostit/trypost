<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import ChannelListItem from '@/components/channels/ChannelListItem.vue';
import ChannelPlatformGrid from '@/components/channels/ChannelPlatformGrid.vue';
import ConnectChannelDialog from '@/components/channels/ConnectChannelDialog.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { usePageErrors } from '@/composables/usePageErrors';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { disconnect } from '@/routes/app/channels';
import { store } from '@/routes/app/welcome/connect';
import type { WelcomeSummary } from '@/types';
import { Platform } from '@/types/platform';
import type {
    AvailablePlatform,
    ConnectedAccount,
} from '@/types/social-account';
import { SocialAccountStatus } from '@/types/social-account-status';

const props = defineProps<{
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
    welcome: WelcomeSummary;
}>();

const form = useForm({});

const { openAt } = useConnectChannelDialog();
const { startConnect, openConnect } = useNetworkConnect(() => props.platforms);

const disconnectModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);

const selectPlatform = (platform: string): void => {
    if (platform === Platform.Instagram || platform === Platform.Telegram) {
        openAt(platform);
        return;
    }

    openConnect(platform);
};

const disconnectChannel = (channel: ConnectedAccount): void => {
    disconnectModal.value?.open({
        url: disconnect.url(channel.id),
        confirmText: channel.handle_label,
    });
};

const errors = usePageErrors();

const hasConnectedAccount = computed((): boolean =>
    props.accounts.some(
        (account) => account.status === SocialAccountStatus.Connected,
    ),
);

const submit = (): void => {
    if (form.processing || !hasConnectedAccount.value) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.connect.title')" />

    <WelcomeLayout
        :title="$t('welcome.connect.title')"
        :description="$t('welcome.connect.description')"
        step="connect"
        size="5xl"
    >
        <div
            v-if="platforms.length > 0"
            class="space-y-6"
            data-testid="welcome-connect-grid"
        >
            <ChannelPlatformGrid
                :platforms="platforms"
                @select="selectPlatform"
            />

            <div v-if="accounts.length > 0" class="space-y-2">
                <ChannelListItem
                    v-for="account in accounts"
                    :key="account.id"
                    :channel="account"
                    :show-settings="false"
                    @reconnect="
                        (channel) => startConnect(channel.platform, channel.id)
                    "
                    @disconnect="disconnectChannel"
                />
            </div>
        </div>

        <ConfirmDeleteModal
            ref="disconnectModal"
            :title="$t('channels.disconnect_modal.title')"
            :description="$t('channels.disconnect_modal.description')"
            :action="$t('channels.disconnect_modal.confirm')"
            :cancel="$t('channels.disconnect_modal.cancel')"
        />

        <template #actions>
            <div
                class="flex flex-col gap-2 sm:flex-row-reverse sm:items-center sm:gap-4"
            >
                <Button as-child size="lg" class="w-full sm:w-auto sm:min-w-48">
                    <button
                        type="button"
                        data-testid="welcome-connect-continue"
                        :disabled="form.processing || !hasConnectedAccount"
                        @click="submit"
                    >
                        {{ $t('welcome.continue') }}
                    </button>
                </Button>
                <InputError
                    data-testid="welcome-connect-error"
                    :message="errors.connect"
                />
            </div>
        </template>
    </WelcomeLayout>

    <ConnectChannelDialog />
</template>
