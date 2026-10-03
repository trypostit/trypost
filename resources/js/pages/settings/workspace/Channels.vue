<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { IconGripVertical, IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelListItem from '@/components/channels/ChannelListItem.vue';
import ChannelsEmptyIllustration from '@/components/channels/ChannelsEmptyIllustration.vue';
import DisconnectChannelDialog from '@/components/channels/DisconnectChannelDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { orderChannels, useChannelOrder } from '@/composables/useChannelOrder';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
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
const reorderable = computed(() => channels.value.length > 1);
const { vSortableChannel, move, onHandleKeydown, dropIndicator } =
    useChannelOrder({
        list: 'settings',
        order: () => channels.value.map((channel) => channel.id),
    });

const page = usePage();
const platforms = computed<AvailablePlatform[]>(
    () => (page.props.connectablePlatforms as AvailablePlatform[]) ?? [],
);
const { open: openConnectDialog } = useConnectChannelDialog();
const { startConnect } = useNetworkConnect(platforms);

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
                    v-for="(channel, index) in channels"
                    :key="channel.id"
                    v-sortable-channel="reorderable ? channel.id : null"
                    class="relative transition-opacity duration-150 data-dragging:opacity-40"
                    :data-testid="`channel-list-row-${channel.id}`"
                >
                    <div
                        v-if="dropIndicator?.channelId === channel.id"
                        class="pointer-events-none absolute inset-x-0 h-0.5 rounded-full bg-primary-strong"
                        :class="dropIndicator.edge === 'top' ? '-top-[7px]' : '-bottom-[7px]'"
                        :data-testid="`channel-drop-indicator-${channel.id}`"
                    />
                    <ChannelListItem
                        :channel="channel"
                        :can-move-up="reorderable && index > 0"
                        :can-move-down="reorderable && index < channels.length - 1"
                        @reconnect="reconnectChannel"
                        @disconnect="disconnectChannel"
                        @move="(moved, offset) => move(moved.id, offset)"
                    >
                        <template v-if="reorderable" #handle>
                            <button
                                type="button"
                                class="-ms-2 flex h-8 w-5 shrink-0 cursor-grab items-center justify-center rounded-md text-muted-foreground outline-hidden hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing"
                                :aria-label="$t('channels.reorder.handle', { name: channel.display_name || channel.username })"
                                :title="$t('channels.reorder.handle', { name: channel.display_name || channel.username })"
                                :data-channel-handle="`settings:${channel.id}`"
                                :data-testid="`channel-handle-${channel.id}`"
                                @keydown="onHandleKeydown($event, channel.id)"
                            >
                                <IconGripVertical class="size-4" />
                            </button>
                        </template>
                    </ChannelListItem>
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
