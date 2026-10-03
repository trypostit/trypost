<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    IconCalendarEvent,
    IconTrendingUp,
    IconChevronDown,
    IconGripVertical,
    IconPlus,
    IconSearch,
    IconSettings,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupLabel,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { orderChannels, useChannelOrder } from '@/composables/useChannelOrder';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { insights, publish } from '@/routes/app/channels';
import { channels as channelsSettings } from '@/routes/app/workspace';
import { channelName, type SidebarChannel } from '@/types/channel';
import type { AvailablePlatform } from '@/types/social-account';
import { isConnectionLost } from '@/types/social-account';

const page = usePage();
const channels = computed<SidebarChannel[]>(() =>
    orderChannels((page.props.channels as SidebarChannel[]) ?? []),
);
const { canManageAccounts, canCreatePost } = useWorkspaceAbilities();
const { vSortableChannel, vSortableChannelList, onHandleKeydown, dragPreview } =
    useChannelOrder({
        list: 'sidebar',
        order: () => channels.value.map((channel) => channel.id),
        placeholder: true,
    });
const reorderable = computed(
    () => canManageAccounts.value && channels.value.length > 1,
);
const draggedChannel = computed<SidebarChannel | null>(
    () =>
        channels.value.find(
            (channel) => channel.id === dragPreview.value?.channelId,
        ) ?? null,
);

type ChannelListEntry =
    | { key: string; channel: SidebarChannel }
    | { key: 'placeholder'; channel: null };

const listEntries = computed<ChannelListEntry[]>(() => {
    const entries: ChannelListEntry[] = channels.value.map((channel) => ({
        key: channel.id,
        channel,
    }));
    const preview = dragPreview.value;

    if (!preview) {
        return entries;
    }

    const anchor = entries.filter(
        (entry) => entry.channel?.id !== preview.channelId,
    )[preview.index];
    const position = anchor ? entries.indexOf(anchor) : entries.length;

    entries.splice(position, 0, { key: 'placeholder', channel: null });

    return entries;
});
const { urlIsActive } = useActiveUrl();
const { open: openConnectDialog } = useConnectChannelDialog();
const { open: openCommandPalette } = useCommandPalette();
const isMacPlatform = /Mac|iPhone|iPad/.test(navigator.platform);
const headerActionClass =
    'static size-6 text-muted-foreground hover:bg-sidebar-action-hover hover:text-muted-foreground active:translate-y-[0.5px] transition-[background-color] duration-150 ease-out motion-reduce:transition-none [&>svg]:size-4';
const { startConnect } = useNetworkConnect(
    () => (page.props.connectablePlatforms as AvailablePlatform[]) ?? [],
);

const SUGGESTED_PLATFORMS = ['instagram', 'tiktok', 'linkedin'];

const SUGGESTED_BACKGROUNDS: Record<string, string> = {
    instagram:
        'bg-[linear-gradient(45deg,#FEDA75_0%,#FA7E1E_25%,#D62976_50%,#962FBF_75%,#4F5BD5_100%)]',
    tiktok: 'bg-black',
    linkedin: 'bg-[#0A66C2]',
};

const suggestedPlatforms = computed(() => {
    const connectable = new Set(
        ((page.props.connectablePlatforms as AvailablePlatform[]) ?? []).map(
            (platform) => platform.value,
        ),
    );

    return SUGGESTED_PLATFORMS.filter((platform) => connectable.has(platform));
});

const isCurrentChannel = (channel: SidebarChannel): boolean =>
    page.url.startsWith(`/channels/${channel.id}/`);

const toggledChannels = ref<Record<string, boolean>>({});

const isExpanded = (channel: SidebarChannel): boolean =>
    toggledChannels.value[channel.id] ?? isCurrentChannel(channel);

const toggleChannel = (channel: SidebarChannel): void => {
    toggledChannels.value = {
        ...toggledChannels.value,
        [channel.id]: !isExpanded(channel),
    };
};

const channelActionClass =
    'top-1.5 rounded-lg text-sidebar-foreground hover:bg-sidebar-action-hover active:translate-y-[0.5px] transition-[opacity,background-color,color,border-color] duration-[200ms,150ms,150ms,150ms] ease-[ease-in-out,ease-out,ease-out,ease-out] [&>svg]:text-muted-foreground md:opacity-0 md:group-hover/channel:opacity-100 md:group-has-focus-visible/channel:opacity-100 motion-reduce:transition-none';

const reconnect = (channel: SidebarChannel): void => {
    if (canManageAccounts.value) {
        startConnect(channel.platform, channel.id);
    }
};
</script>

<template>
    <SidebarGroup
        class="group/channels min-h-0 flex-1 px-4 pt-4 pb-0 group-data-[collapsible=icon]:px-2.5"
        data-testid="sidebar-channels"
    >
        <div class="group/channels-header relative">
            <SidebarGroupLabel
                class="group-data-[collapsible=icon]:hidden"
                data-testid="sidebar-channels-label"
            >
                <template v-if="channels.length > 0"
                    >{{ $t('sidebar.channels') }} · {{ channels.length }}</template
                >
                <template v-else-if="canManageAccounts">{{
                    $t('sidebar.connect_channels')
                }}</template>
                <template v-else>{{ $t('sidebar.channels') }}</template>
            </SidebarGroupLabel>
            <div
                :class="[
                    'absolute top-1 right-0 flex items-center gap-1 transition-opacity duration-(--motion-duration-sidebar-toggle) ease-(--motion-easing-sidebar-toggle) group-data-[collapsible=icon]:hidden',
                    channels.length > 0
                        ? 'focus-within:opacity-100 md:opacity-0 md:group-hover/channels-header:opacity-100'
                        : '',
                ]"
                data-testid="sidebar-channels-actions"
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <SidebarGroupAction
                            :class="['rounded-lg', headerActionClass]"
                            :aria-label="$t('sidebar.search_channels')"
                            :aria-keyshortcuts="isMacPlatform ? 'Meta+K' : 'Control+K'"
                            data-testid="sidebar-channels-search"
                            @click="openCommandPalette()"
                        >
                            <IconSearch />
                        </SidebarGroupAction>
                    </TooltipTrigger>
                    <TooltipContent
                        side="top"
                        class="flex items-center gap-2"
                        data-testid="sidebar-channels-search-tooltip"
                    >
                        {{ $t('sidebar.search_channels') }}
                        <KbdGroup data-testid="sidebar-channels-search-shortcut">
                            <Kbd>{{ isMacPlatform ? '⌘' : 'Ctrl' }}</Kbd>
                            <Kbd>K</Kbd>
                        </KbdGroup>
                    </TooltipContent>
                </Tooltip>
                <SidebarGroupAction
                    v-if="canManageAccounts"
                    as-child
                    :class="['rounded-md', headerActionClass]"
                    :title="$t('channels.settings')"
                >
                    <Link
                        :href="channelsSettings.url()"
                        :aria-label="$t('channels.settings')"
                        data-testid="sidebar-channels-settings"
                    >
                        <IconSettings />
                    </Link>
                </SidebarGroupAction>
                <SidebarGroupAction
                    v-if="canManageAccounts"
                    :class="['rounded-lg', headerActionClass]"
                    :title="$t('channels.connect')"
                    :aria-label="$t('channels.connect')"
                    data-testid="sidebar-channels-connect"
                    @click="openConnectDialog()"
                >
                    <IconPlus />
                </SidebarGroupAction>
            </div>
        </div>
        <div
            aria-hidden="true"
            class="mb-4 hidden h-px bg-sidebar-border group-data-[collapsible=icon]:block"
        />

        <TransitionGroup
            v-sortable-channel-list="reorderable"
            tag="ul"
            move-class="transition-transform duration-200 ease-out motion-reduce:transition-none"
            data-slot="sidebar-menu"
            data-sidebar="menu"
            class="relative -mx-4 mt-1 flex min-h-0 w-auto min-w-0 flex-col gap-2 overflow-y-auto px-4 pb-4 group-data-[collapsible=icon]:-mx-2.5 group-data-[collapsible=icon]:px-2.5"
            data-testid="sidebar-channels-list"
        >
            <SidebarMenuItem
                v-if="channels.length === 0"
                key="empty"
            >
                <div
                    v-if="canManageAccounts"
                    class="flex items-center gap-2 px-2 pt-1 pb-2"
                >
                    <Tooltip
                        v-for="platform in suggestedPlatforms"
                        :key="platform"
                    >
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                :class="[
                                    'flex size-8 items-center justify-center rounded-[10px] shadow-xs ring-1 ring-black/5 transition-transform duration-150 ease-out ring-inset group-data-[collapsible=icon]:hidden hover:-translate-y-0.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:translate-y-0 motion-reduce:transition-none [&>svg]:size-[18px] [&>svg]:stroke-[2.25]',
                                    SUGGESTED_BACKGROUNDS[platform],
                                ]"
                                :aria-label="
                                    $t('channels.details.connect', {
                                        network: getPlatformLabel(platform),
                                    })
                                "
                                :data-testid="`sidebar-channels-empty-connect-${platform}`"
                                @click="startConnect(platform)"
                            >
                                <PlatformBrandIcon :platform="platform" inverse />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                            {{
                                $t('channels.details.connect', {
                                    network: getPlatformLabel(platform),
                                })
                            }}
                        </TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="flex size-8 items-center justify-center rounded-[10px] border border-dashed border-border-strong text-muted-foreground transition-control hover:border-solid hover:bg-sidebar-action-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :aria-label="$t('channels.connect')"
                                data-testid="sidebar-channels-empty"
                                @click="openConnectDialog()"
                            >
                                <IconPlus class="size-4" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                            {{ $t('channels.connect') }}
                        </TooltipContent>
                    </Tooltip>
                </div>
                <p
                    v-else
                    class="px-2 py-1.5 text-xs text-muted-foreground"
                    data-testid="sidebar-channels-empty"
                >
                    {{ $t('channels.empty') }}
                </p>
            </SidebarMenuItem>

            <template
                v-for="{ key, channel } in listEntries"
                :key="key"
            >
                <li
                    v-if="channel === null"
                    aria-hidden="true"
                    class="shrink-0 cursor-grabbing rounded-lg"
                    :class="
                        draggedChannel
                            ? null
                            : 'border border-dashed border-sidebar-border bg-sidebar-accent/60'
                    "
                    :style="{ height: `${dragPreview?.height ?? 0}px` }"
                    data-testid="sidebar-channel-placeholder"
                >
                    <div
                        v-if="draggedChannel"
                        class="flex h-9 items-center gap-2 rounded-lg bg-background ps-2 pe-2 shadow-md ring-1 ring-sidebar-border group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0.5"
                        data-testid="sidebar-channel-placeholder-preview"
                    >
                        <ChannelAvatar
                            :platform="draggedChannel.platform"
                            :src="draggedChannel.avatar_url"
                            :name="channelName(draggedChannel)"
                            :size="28"
                            ring="sidebar"
                        />
                        <span
                            class="truncate text-sm group-data-[collapsible=icon]:hidden"
                            >{{ channelName(draggedChannel) }}</span
                        >
                    </div>
                </li>
                <SidebarMenuItem
                    v-else
                    v-sortable-channel="reorderable ? channel.id : null"
                    class="group/channel"
                    :class="{ hidden: dragPreview?.channelId === channel.id }"
                    :data-testid="`sidebar-channel-row-${channel.id}`"
                >
                    <button
                        v-if="reorderable"
                        type="button"
                        class="absolute top-2 -start-3.5 z-10 flex h-5 w-3.5 cursor-grab items-center justify-center rounded-sm text-muted-foreground opacity-0 outline-hidden transition-opacity group-hover/channel:opacity-100 group-data-[collapsible=icon]:hidden focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-sidebar-ring active:cursor-grabbing max-md:hidden"
                        :aria-label="$t('channels.reorder.handle', { name: channelName(channel) })"
                        :title="$t('channels.reorder.handle', { name: channelName(channel) })"
                        :data-channel-handle="`sidebar:${channel.id}`"
                        :data-testid="`sidebar-channel-handle-${channel.id}`"
                        @keydown="onHandleKeydown($event, channel.id)"
                    >
                        <IconGripVertical class="size-3.5" />
                    </button>
                    <SidebarMenuButton
                        as-child
                        :is-active="isCurrentChannel(channel)"
                        class="h-9 gap-2 py-1 ps-2 max-md:pe-16 group-hover/channel:pe-16 group-has-focus-visible/channel:pe-16 data-[active=true]:bg-transparent data-[active=true]:hover:bg-sidebar-accent [&:has(~[data-sidebar=group-action]:hover)]:bg-sidebar-accent group-data-[collapsible=icon]:p-0.5! group-data-[collapsible=icon]:data-[active=true]:bg-sidebar-accent"
                        :tooltip="
                            isConnectionLost(channel) && !canManageAccounts
                                ? `${channelName(channel)} - ${$t('channels.connection_lost_hint')}`
                                : channelName(channel)
                        "
                    >
                        <Link
                            :href="publish.url(channel.id)"
                            :data-testid="`sidebar-channel-${channel.id}`"
                        >
                            <ChannelAvatar
                                :platform="channel.platform"
                                :src="channel.avatar_url"
                                :name="channelName(channel)"
                                :size="28"
                                ring="sidebar"
                                :status="canManageAccounts ? null : channel.status"
                                :account-id="channel.id"
                            />
                            <span class="truncate text-sm">{{ channelName(channel) }}</span>
                        </Link>
                    </SidebarMenuButton>
                    <TooltipProvider
                        v-if="isConnectionLost(channel) && canManageAccounts"
                        :delay-duration="200"
                    >
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    class="absolute start-1.5 top-0.5 z-10 size-2 cursor-pointer rounded-full bg-destructive ring-2 ring-sidebar outline-hidden focus-visible:ring-sidebar-ring group-data-[collapsible=icon]:start-0 group-data-[collapsible=icon]:top-0"
                                    :aria-label="$t('channels.reconnect')"
                                    :data-testid="`sidebar-channel-${channel.id}-lost`"
                                    @click="reconnect(channel)"
                                />
                            </TooltipTrigger>
                            <TooltipContent side="right">
                                {{ $t('channels.connection_lost_hint') }}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                    <SidebarMenuBadge
                        :class="[
                            'top-2 right-0 group-hover/channel:hidden group-has-focus-visible/channel:hidden max-md:hidden',
                            isExpanded(channel) ? 'hidden' : '',
                        ]"
                        :data-testid="`sidebar-channel-count-${channel.id}`"
                    >
                        {{ channel.scheduled_posts_count }}
                    </SidebarMenuBadge>
                    <SidebarGroupAction
                        v-if="canCreatePost"
                        :class="['right-[30px]', channelActionClass]"
                        :title="$t('channels.new_post')"
                        :data-testid="`sidebar-channel-${channel.id}-new-post`"
                        @click="openPostComposer({ socialAccountIds: [channel.id] })"
                    >
                        <IconPlus />
                    </SidebarGroupAction>

                    <SidebarGroupAction
                        :class="['right-0.5 aria-expanded:bg-transparent', channelActionClass]"
                        :aria-label="
                            $t('sidebar.channel_submenu', {
                                name: channelName(channel),
                            })
                        "
                        :aria-expanded="isExpanded(channel)"
                        :data-testid="`sidebar-channel-${channel.id}-toggle`"
                        @click="toggleChannel(channel)"
                    >
                        <IconChevronDown
                            :class="[
                                'transition-transform duration-200 ease-[ease] motion-reduce:transition-none',
                                isExpanded(channel) ? 'rotate-180' : '',
                            ]"
                        />
                    </SidebarGroupAction>

                    <div
                        class="motion-collapse"
                        :data-expanded="isExpanded(channel)"
                        :data-testid="`sidebar-channel-${channel.id}-submenu`"
                    >
                        <div>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem>
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="urlIsActive(publish.url(channel.id))"
                                    >
                                        <Link
                                            :href="publish.url(channel.id)"
                                            :data-testid="`sidebar-channel-${channel.id}-publish`"
                                        >
                                            <IconCalendarEvent />
                                            <span class="min-w-0 flex-1 truncate">{{ $t('channels.publish') }}</span>
                                            <span
                                                v-if="channel.scheduled_posts_count > 0"
                                                class="flex w-6 shrink-0 justify-center tabular-nums"
                                                :data-testid="`sidebar-channel-${channel.id}-publish-count`"
                                            >{{ channel.scheduled_posts_count }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                                <SidebarMenuSubItem>
                                    <SidebarMenuSubButton
                                        as-child
                                        :is-active="urlIsActive(insights.url(channel.id))"
                                    >
                                        <Link
                                            :href="insights.url(channel.id)"
                                            :data-testid="`sidebar-channel-${channel.id}-insights`"
                                        >
                                            <IconTrendingUp />
                                            <span>{{ $t('channels.insights') }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </div>
                    </div>
                </SidebarMenuItem>
            </template>
        </TransitionGroup>
    </SidebarGroup>
</template>
