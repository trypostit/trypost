<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelListItem from '@/components/channels/ChannelListItem.vue';
import ChannelsEmptyIllustration from '@/components/channels/ChannelsEmptyIllustration.vue';
import DisconnectChannelDialog from '@/components/channels/DisconnectChannelDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { orderChannels } from '@/composables/useChannelOrder';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { Platform } from '@/types/platform';
import type {
    AvailablePlatform,
    ConnectedAccount,
} from '@/types/social-account';

const props = defineProps<{
    connectedChannels: ConnectedAccount[];
}>();

const channels = computed<ConnectedAccount[]>(() =>
    orderChannels(props.connectedChannels),
);

const page = usePage();
const platforms = computed<AvailablePlatform[]>(
    () => (page.props.connectablePlatforms as AvailablePlatform[]) ?? [],
);
const { open: openConnectDialog, openAt } = useConnectChannelDialog();
const { startConnect, instagramMethods } = useNetworkConnect(platforms);

const canLinkFacebook = (channel: ConnectedAccount): boolean =>
    channel.platform === Platform.Instagram &&
    instagramMethods.value.includes(Platform.InstagramFacebook);

const linkFacebook = (channel: ConnectedAccount): void =>
    openAt(Platform.InstagramFacebook, channel.id);

const disconnectDialog = ref<InstanceType<
    typeof DisconnectChannelDialog
> | null>(null);

const reconnectChannel = (channel: ConnectedAccount): void =>
    startConnect(channel.platform, channel.id);

const disconnectChannel = (channel: ConnectedAccount): void =>
    disconnectDialog.value?.open(channel);
</script>

<template>
    <Head :title="$t('channels.title')" />

    <SettingsLayout
        :title="$t('channels.title')"
        :description="$t('channels.description')"
        :centered="channels.length === 0"
    >
        <template #actions>
            <Button data-testid="channels-connect" @click="openConnectDialog()">
                <IconPlus class="size-4" />
                {{ $t('channels.connect') }}
            </Button>
        </template>

            <ul v-if="channels.length > 0" class="flex flex-col gap-3">
                <li
                    v-for="channel in channels"
                    :key="channel.id"
                    :data-testid="`channel-list-row-${channel.id}`"
                >
                    <ChannelListItem
                        :channel="channel"
                        :can-link-facebook="canLinkFacebook(channel)"
                        @reconnect="reconnectChannel"
                        @link-facebook="linkFacebook"
                        @disconnect="disconnectChannel"
                    />
                </li>
            </ul>

            <EmptyState
                v-else
                :title="$t('channels.empty')"
                :description="$t('channels.empty_description')"
                data-testid="channels-empty"
            >
                <template #illustration>
                    <ChannelsEmptyIllustration />
                </template>
                <template #action>
                    <Button
                        data-testid="channels-empty-connect"
                        @click="openConnectDialog()"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('channels.connect') }}
                    </Button>
                </template>
            </EmptyState>

        <DisconnectChannelDialog
            ref="disconnectDialog"
            :keyword="$t('channels.disconnect_modal.keyword')"
            @refresh="reconnectChannel"
        />
    </SettingsLayout>
</template>
