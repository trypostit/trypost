<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelListItem from '@/components/channels/ChannelListItem.vue';
import ChannelsEmptyIllustration from '@/components/channels/ChannelsEmptyIllustration.vue';
import DisconnectChannelDialog from '@/components/channels/DisconnectChannelDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import NetworkFilter from '@/components/NetworkFilter.vue';
import SettingsSearch from '@/components/settings/SettingsSearch.vue';
import { Button } from '@/components/ui/button';
import { orderChannels } from '@/composables/useChannelOrder';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { CHANNEL_LIST_COLUMNS } from '@/lib/channelList';
import { blank, filled } from '@/lib/helpers';
import { filterBySearch } from '@/lib/search';
import { Platform } from '@/types/platform';
import type {
    AvailablePlatform,
    ChannelListRow,
    ConnectedAccount,
} from '@/types/social-account';

const props = defineProps<{
    connectedChannels: ChannelListRow[];
}>();

const channels = computed<ChannelListRow[]>(() =>
    orderChannels(props.connectedChannels),
);

const search = ref('');
const selectedNetworks = ref<string[]>([]);

/** The networks of the connected channels, each once, in channel order. */
const networks = computed(() => [
    ...new Set(channels.value.map((channel) => channel.network)),
]);

const visibleChannels = computed<ChannelListRow[]>(() =>
    filterBySearch(
        channels.value.filter(
            (channel) =>
                blank(selectedNetworks.value) ||
                selectedNetworks.value.includes(channel.network),
        ),
        search.value,
        (channel) => [
            channel.display_name,
            channel.username,
            channel.handle_label,
        ],
    ),
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
        :centered="blank(channels)"
        :total="filled(channels) ? channels.length : null"
        wide
    >
        <template #actions>
            <Button data-testid="channels-connect" @click="openConnectDialog()">
                <IconPlus class="size-4" />
                {{ $t('channels.connect') }}
            </Button>
        </template>

            <div v-if="filled(channels)" class="flex flex-col gap-3">
                <div class="flex items-center gap-2" data-testid="channels-toolbar">
                    <SettingsSearch
                        v-model="search"
                        class="min-w-0 flex-1"
                        :placeholder="$t('channels.list.search')"
                        compact
                    />
                    <NetworkFilter
                        v-if="networks.length > 1"
                        v-model="selectedNetworks"
                        :networks="networks"
                        test-id="channels-network"
                        sheet-below-sm
                    />
                </div>

                <div
                    class="overflow-hidden rounded-xl border border-border bg-card"
                    data-testid="channels-list"
                >
                    <div
                        class="hidden gap-x-3 border-b border-border bg-secondary/50 px-4 py-2 text-xs font-medium text-muted-foreground md:grid"
                        :class="CHANNEL_LIST_COLUMNS"
                        aria-hidden="true"
                    >
                        <span>{{ $t('channels.list.columns.channel') }}</span>
                        <span>{{ $t('channels.list.columns.timezone') }}</span>
                        <span>{{ $t('channels.list.columns.posting_times') }}</span>
                        <span>{{ $t('channels.list.columns.this_week') }}</span>
                        <span />
                    </div>
                    <ul class="divide-y divide-border">
                        <li
                            v-for="channel in visibleChannels"
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
                    <p
                        v-if="blank(visibleChannels)"
                        class="px-4 py-10 text-center text-sm text-muted-foreground"
                        data-testid="channels-no-results"
                    >
                        {{ $t('channels.list.no_results') }}
                    </p>
                </div>
            </div>

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
