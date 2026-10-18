<script setup lang="ts">
import {
    IconCheck,
    IconChevronDown,
    IconFilter2,
    IconMessages,
} from '@tabler/icons-vue';
import { wTrans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

import BottomSheet from '@/components/BottomSheet.vue';
import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import PostChannelFilter from '@/components/posts/PostChannelFilter.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { DATE_RANGE_KEYS, DEFAULT_INBOX_FILTERS } from '@/lib/inbox';
import type {
    InboxChannel,
    InboxDateRange,
    InboxFilterChannel,
    InboxFilters,
    InboxSort,
    InboxStatusFilter,
    InboxTab,
    InboxTabCounts,
} from '@/types/inbox';

const props = defineProps<{
    filters: InboxFilters;
    channels: InboxChannel[];
    filterChannels: InboxFilterChannel[];
    counts: InboxTabCounts;
    tabsOnly?: boolean;
}>();

const emit = defineEmits<{
    change: [filters: InboxFilters];
}>();

const STATUSES: InboxStatusFilter[] = ['all', 'unanswered', 'replied', 'resolved'];
const SORTS: InboxSort[] = ['unanswered_first', 'newest', 'oldest'];
const RANGES: InboxDateRange[] = ['7d', '30d', '6m', '12m'];

const tabs = computed<{ tab: InboxTab; count: number }[]>(() => [
    { tab: 'comments', count: props.counts.comments },
    ...(props.counts.mentions === null
        ? []
        : [{ tab: 'mentions' as const, count: props.counts.mentions }]),
    ...(props.counts.messages === null || props.counts.messages === undefined
        ? []
        : [{ tab: 'messages' as const, count: props.counts.messages }]),
]);

const STATUS_LABEL_KEYS: Record<InboxTab, string> = {
    comments: 'inbox.filters.status_label',
    mentions: 'inbox.filters.status_label_mentions',
    messages: 'inbox.filters.status_label_messages',
};

const TAB_COUNT_KEYS: Record<InboxTab, string> = {
    comments: 'inbox.posts.open',
    mentions: 'inbox.mentions.open_count',
    messages: 'inbox.direct.open_count',
};

const activeTab = computed(
    () => tabs.value.find((item) => item.tab === props.filters.tab) ?? tabs.value[0],
);

interface UnavailableReason {
    key: string;
    params?: Record<string, string>;
}

/**
 * On Mentions and Messages, a channel whose network has no such tab, or that
 * must be reconnected for it, cannot be picked. The reason stays a lang key so
 * the template resolves it.
 */
const reasonFor = (tab: InboxTab, channel: InboxChannel): UnavailableReason | null => {
    const network = { network: getPlatformLabel(channel.platform) };

    if (tab === 'mentions') {
        if (!channel.supports_mentions) {
            return { key: 'inbox.mentions.unsupported_channel', params: network };
        }

        return channel.has_mention_access ? null : { key: 'inbox.mentions.reconnect_channel' };
    }

    if (tab === 'messages') {
        if (!channel.supports_direct_messages) {
            return { key: 'inbox.direct.unsupported_channel', params: network };
        }

        return channel.has_direct_message_access ? null : { key: 'inbox.direct.reconnect_channel' };
    }

    return null;
};

const NO_INBOX_REASON: UnavailableReason = { key: 'inbox.filters.unavailable.network' };

/** Why a channel cannot be picked here: its network has no inbox, or the current tab does not cover it. */
const disabledReasonFor = (channel: InboxFilterChannel): string | null => {
    const inboxChannel = props.channels.find((item) => item.id === channel.id);
    const reason =
        channel.has_inbox && inboxChannel
            ? reasonFor(props.filters.tab, inboxChannel)
            : NO_INBOX_REASON;

    return reason ? wTrans(reason.key, reason.params).value : null;
};

const channelOptions = computed(() =>
    props.filterChannels.map((channel) => ({
        id: channel.id,
        platform: channel.platform,
        display_label: channel.display_name || channel.username,
        username: channel.username,
        avatar_url: channel.avatar_url,
        status: channel.status,
        disabled_reason: disabledReasonFor(channel),
    })),
);

const change = (patch: Partial<InboxFilters>): void => {
    emit('change', { ...props.filters, ...patch });
};

const sameIds = (left: string[], right: string[]): boolean =>
    left.length === right.length && left.every((id) => right.includes(id));

const selectedChannelIds = ref<string[]>([...props.filters.channels]);

watch(
    () => props.filters.channels,
    (channels) => {
        if (!sameIds(selectedChannelIds.value, channels)) {
            selectedChannelIds.value = [...channels];
        }
    },
);

watch(
    selectedChannelIds,
    (channelIds) => {
        if (!sameIds(channelIds, props.filters.channels)) {
            change({ channels: channelIds });
        }
    },
    { deep: true },
);

/** The picked channels that can still be used on a tab. */
const channelsUsableOn = (tab: InboxTab): string[] =>
    props.filters.channels.filter((id) => {
        const channel = props.channels.find((item) => item.id === id);

        return channel !== undefined && !reasonFor(tab, channel);
    });

const selectStatus = (status: InboxStatusFilter): void => {
    change({ status });
};

const selectSort = (sort: InboxSort): void => {
    change({ sort });
};

const selectRange = (range: InboxDateRange): void => {
    change({ range });
};

const resetFilters = (): void => {
    emit('change', { ...DEFAULT_INBOX_FILTERS, tab: props.filters.tab });
};

const selectTab = (tab: InboxTab): void => {
    isTabSheetOpen.value = false;

    if (tab === props.filters.tab) {
        return;
    }

    change({ tab, channels: channelsUsableOn(tab) });
};

const isTabSheetOpen = ref<boolean>(false);

const openTabSheet = (): void => {
    isTabSheetOpen.value = true;
};
</script>

<template>
    <div
        class="mt-[11px] flex min-w-0 items-center justify-between gap-4 border-b border-border px-4 max-md:pt-1.5 max-md:pb-4 md:px-8"
        data-testid="inbox-filters"
    >
        <nav class="flex shrink-0 gap-4 max-md:hidden" :aria-label="$t('inbox.title')">
            <button
                v-for="item in tabs"
                :key="item.tab"
                type="button"
                :aria-current="item.tab === activeTab.tab ? 'page' : undefined"
                class="relative inline-flex h-[45px] shrink-0 items-center gap-2 px-2 py-3 text-sm leading-[21px] font-medium transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :class="
                    item.tab === activeTab.tab
                        ? 'text-foreground after:absolute after:inset-x-0.5 after:-bottom-px after:h-px after:bg-primary-text'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :data-testid="`inbox-tab-${item.tab}`"
                @click="selectTab(item.tab)"
            >
                {{ $t(`inbox.tabs.${item.tab}`) }}
                <span
                    class="inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full px-1 text-xs font-medium tabular-nums"
                    :class="
                        item.count > 0
                            ? 'bg-primary-subtle text-primary-text'
                            : 'bg-secondary text-foreground'
                    "
                    :aria-label="$tChoice(TAB_COUNT_KEYS[item.tab], item.count, { count: String(item.count) })"
                    :data-testid="item.tab === 'comments' ? 'inbox-open-count' : `inbox-${item.tab}-count`"
                    >{{ item.count }}</span
                >
            </button>
        </nav>

        <button
            type="button"
            class="inline-flex h-8 min-w-0 items-center gap-1 rounded-lg px-1 text-base font-medium text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring md:hidden"
            :aria-expanded="isTabSheetOpen"
            aria-haspopup="dialog"
            data-testid="inbox-tab-menu"
            @click="openTabSheet"
        >
            <span class="truncate">{{ $t(`inbox.tabs.${activeTab.tab}`) }}</span>
            <span
                class="inline-flex h-4.5 min-w-4.5 shrink-0 items-center justify-center rounded-full px-1 text-sm font-medium tabular-nums"
                :class="
                    activeTab.count > 0
                        ? 'bg-primary-subtle text-primary-text'
                        : 'bg-secondary text-foreground'
                "
                data-testid="inbox-tab-menu-count"
                >{{ activeTab.count }}</span
            >
            <IconChevronDown class="size-4 shrink-0 text-muted-foreground" />
        </button>

        <BottomSheet
            v-model:open="isTabSheetOpen"
            :title="$t('inbox.title')"
            test-id="inbox-tab-sheet"
            :show-header="false"
            content-class="rounded-t-lg border pt-7 pb-4 md:hidden"
        >
            <span
                class="absolute start-1/2 top-[13px] h-[5px] w-10 -translate-x-1/2 rounded-full bg-border-strong rtl:translate-x-1/2"
                aria-hidden="true"
            />
            <nav class="flex flex-col px-4 py-2" :aria-label="$t('inbox.title')">
                <button
                    v-for="item in tabs"
                    :key="item.tab"
                    type="button"
                    :aria-current="item.tab === activeTab.tab ? 'page' : undefined"
                    class="flex h-[42px] items-center gap-2 rounded-md px-3 text-start text-base leading-4 font-medium text-foreground transition-control"
                    :class="item.tab === activeTab.tab ? 'bg-accent' : 'hover:bg-accent'"
                    :data-testid="`inbox-tab-sheet-${item.tab}`"
                    @click="selectTab(item.tab)"
                >
                    {{ $t(`inbox.tabs.${item.tab}`) }}
                    <span
                        class="inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full px-1 text-sm font-medium tabular-nums"
                        :class="
                            item.count > 0
                                ? 'bg-primary-subtle text-primary-text'
                                : 'bg-secondary text-foreground'
                        "
                        >{{ item.count }}</span
                    >
                    <IconCheck v-if="item.tab === activeTab.tab" class="ms-auto size-4" />
                </button>
            </nav>
        </BottomSheet>

        <div
            v-if="!tabsOnly"
            class="flex min-w-0 items-center gap-1 max-md:shrink-0 md:gap-3"
        >
            <PostChannelFilter
                v-if="!filters.channel"
                v-model="selectedChannelIds"
                :channels="channelOptions"
                test-id="inbox-channel"
                bare-on-mobile
            />

            <div class="contents max-md:hidden">
            <InboxTooltip :label="$t(STATUS_LABEL_KEYS[filters.tab])" wrap>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        class="shrink-0 data-[state=open]:bg-accent"
                        :aria-label="`${$t(STATUS_LABEL_KEYS[filters.tab])}: ${$t(`inbox.filters.status.${filters.status}`)}`"
                        data-testid="inbox-status-filter"
                    >
                        <IconMessages class="size-4 text-muted-foreground" />
                        <span class="hidden sm:inline">{{
                            $t(`inbox.filters.status.${filters.status}`)
                        }}</span>
                        <IconChevronDown class="size-4 text-muted-foreground" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="status in STATUSES"
                        :key="status"
                        :data-testid="`inbox-status-${status}`"
                        @select="selectStatus(status)"
                    >
                        <IconCheck
                            class="size-4"
                            :class="filters.status === status ? '' : 'invisible'"
                        />
                        {{ $t(`inbox.filters.status.${status}`) }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            </InboxTooltip>
            </div>

            <InboxTooltip :label="$t('inbox.filters.menu')" wrap>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="shrink-0 data-[state=open]:bg-accent"
                        :aria-label="$t('inbox.filters.menu')"
                        data-testid="inbox-sort-menu"
                    >
                        <IconFilter2
                            class="size-4 text-muted-foreground"
                        />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <div class="md:hidden">
                        <DropdownMenuLabel
                            class="text-xs font-normal text-muted-foreground"
                        >
                            {{ $t(STATUS_LABEL_KEYS[filters.tab]) }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="status in STATUSES"
                            :key="status"
                            :data-testid="`inbox-menu-status-${status}`"
                            @select="selectStatus(status)"
                        >
                            {{ $t(`inbox.filters.status.${status}`) }}
                            <IconCheck
                                v-if="filters.status === status"
                                class="ms-auto size-4"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </div>
                    <DropdownMenuLabel
                        class="text-xs font-normal text-muted-foreground"
                    >
                        {{ $t('inbox.filters.sort_by') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="sort in SORTS"
                        :key="sort"
                        :data-testid="`inbox-sort-${sort}`"
                        @select="selectSort(sort)"
                    >
                        {{ $t(`inbox.filters.sort.${sort}`) }}
                        <IconCheck
                            v-if="filters.sort === sort"
                            class="ms-auto size-4"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel
                        class="text-xs font-normal text-muted-foreground"
                    >
                        {{ $t('inbox.filters.range_label') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="range in RANGES"
                        :key="range"
                        :data-testid="`inbox-range-${range}`"
                        @select="selectRange(range)"
                    >
                        {{ $t(`inbox.filters.range.${DATE_RANGE_KEYS[range]}`) }}
                        <IconCheck
                            v-if="filters.range === range"
                            class="ms-auto size-4"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        data-testid="inbox-reset-filters"
                        @select="resetFilters"
                    >
                        {{ $t('inbox.filters.reset') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            </InboxTooltip>

            <slot name="actions" />
            <slot name="view" />
        </div>
    </div>
</template>
