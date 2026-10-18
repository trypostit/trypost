<script setup lang="ts">
import { Deferred, Head, router, usePage } from '@inertiajs/vue3';
import { IconMessages } from '@tabler/icons-vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
import InboxBulkResolveMenu from '@/components/inbox/InboxBulkResolveMenu.vue';
import InboxConversation from '@/components/inbox/InboxConversation.vue';
import InboxDirectList from '@/components/inbox/InboxDirectList.vue';
import InboxDirectThread from '@/components/inbox/InboxDirectThread.vue';
import InboxEmptyState from '@/components/inbox/InboxEmptyState.vue';
import InboxFeed from '@/components/inbox/InboxFeed.vue';
import InboxFilters from '@/components/inbox/InboxFilters.vue';
import InboxHeader from '@/components/inbox/InboxHeader.vue';
import InboxListeningState from '@/components/inbox/InboxListeningState.vue';
import InboxMentionsAvailability from '@/components/inbox/InboxMentionsAvailability.vue';
import InboxPostList from '@/components/inbox/InboxPostList.vue';
import InboxPostNavigator from '@/components/inbox/InboxPostNavigator.vue';
import InboxReconnectBanner from '@/components/inbox/InboxReconnectBanner.vue';
import InboxScoreBadge from '@/components/inbox/InboxScoreBadge.vue';
import InboxSyncErrorBanner from '@/components/inbox/InboxSyncErrorBanner.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useInboxRealtime } from '@/composables/inbox/useInboxRealtime';
import { useInboxSyncErrors } from '@/composables/inbox/useInboxSyncErrors';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    applyInboxFilters,
    channelShowsInTab,
    commentsView,
    conversationUrl,
    inboxPostUrl,
    inboxViewUrl,
    isInboxFiltered,
    isSameInboxItem,
    rememberPosts,
    rememberCommentsView,
    rememberedPosts,
    syncErrorInTab,
} from '@/lib/inbox';
import { store as dismissReorderHint } from '@/routes/app/inbox/reorder-hint';
import type {
    InboxChannel,
    InboxCommentScore,
    InboxConversation as InboxConversationData,
    InboxFilterChannel,
    InboxFilters as InboxFiltersData,
    InboxMentionNetwork,
    InboxMessage,
    InboxPostConversation,
    InboxScrollProp,
    InboxTabCounts,
    InboxView,
} from '@/types/inbox';

const props = defineProps<{
    view: InboxView;
    filters: InboxFiltersData;
    conversations: InboxScrollProp<InboxPostConversation> | null;
    conversation: InboxPostConversation | null;
    conversationThreads: InboxScrollProp<InboxMessage> | null;
    directMessages: InboxScrollProp<InboxMessage> | null;
    isConversationOpened: boolean;
    messages: InboxScrollProp<InboxMessage> | null;
    repliedRecentlyCount: number | null;
    tabCounts: InboxTabCounts;
    commentScore?: InboxCommentScore;
    mentionsAvailability: InboxMentionNetwork[];
    inboxChannels: InboxChannel[];
    filterChannels: InboxFilterChannel[];
    hasInboxChannels: boolean;
    hasConversations: boolean;
    showsReorderHint: boolean;
    backfillDays: number;
    canReply: boolean;
}>();

const conversationColumn = useTemplateRef<HTMLElement>('conversationColumn');

const isPostView = computed(() => props.view === 'post');

const isMentionsTab = computed(() => props.filters.tab === 'mentions');

const isMessagesView = computed(() => props.view === 'messages');

/** By post and List only exist for comments. */
const showsViewToggle = computed(
    () => !isMentionsTab.value && !isMessagesView.value && !isReviewOnlyScope.value,
);

const showsMentionsAvailability = computed(
    () =>
        isMentionsTab.value &&
        !props.inboxChannels.some((channel) => channel.supports_mentions),
);

const isFiltered = computed(() => isInboxFiltered(props.filters));

/** The channels the filters keep: the picked ones, or every channel when none is picked. */
const channelsInScope = (channelIds: string[]): InboxChannel[] =>
    channelIds.length
        ? props.inboxChannels.filter((channel) => channelIds.includes(channel.id))
        : props.inboxChannels;

const channelsWithoutAccess = computed(() =>
    props.inboxChannels.filter(
        (channel) =>
            (!channel.has_inbox_access || !channel.is_connected) &&
            channelShowsInTab(channel, props.filters.tab),
    ),
);

const syncErrors = useInboxSyncErrors();

/** Channels that can sync but whose sync for this tab stopped, with why. */
const channelsWithSyncError = computed(() =>
    props.inboxChannels.flatMap((channel) => {
        const error = channel.has_inbox_access && channel.is_connected ? syncErrorInTab(channel, props.filters.tab) : null;

        return error && !syncErrors.isDismissed(channel, error) ? [{ channel, error }] : [];
    }),
);

const hasBanners = computed(
    () =>
        channelsWithoutAccess.value.length > 0 ||
        channelsWithoutMentionAccess.value.length > 0 ||
        channelsWithoutMessageAccess.value.length > 0 ||
        channelsWithSyncError.value.length > 0,
);

const channelsWithoutMentionAccess = computed(() =>
    isMentionsTab.value
        ? props.inboxChannels.filter(
              (channel) =>
                  channel.supports_mentions &&
                  channel.has_inbox_access &&
                  channel.is_connected &&
                  !channel.has_mention_access,
          )
        : [],
);

const channelsWithoutMessageAccess = computed(() =>
    isMessagesView.value
        ? props.inboxChannels.filter(
              (channel) =>
                  channel.supports_direct_messages &&
                  channel.has_inbox_access &&
                  channel.is_connected &&
                  !channel.has_direct_message_access,
          )
        : [],
);

const directMessageList = computed(() => props.directMessages?.data ?? []);

const showsThreadOnMobile = ref(props.isConversationOpened);

const showConversationList = (): void => {
    showsThreadOnMobile.value = false;
};

const conversationChannel = computed(
    () =>
        props.inboxChannels.find(
            (channel) => channel.id === props.conversation?.channel.id,
        ) ?? null,
);


/** The newest direct message loaded, which an open conversation's card previews. */
const newestDirectMessage = computed(() =>
    directMessageList.value.reduce<InboxMessage | null>(
        (newest, message) =>
            !newest || (message.platform_created_at ?? '') > (newest.platform_created_at ?? '') ? message : newest,
        null,
    ),
);

/**
 * The open post or conversation is read again after every action, so its card
 * takes those numbers; the rest of the list stays as loaded until a reorder.
 */
const conversationList = computed(() => {
    const open = props.conversation;

    return (props.conversations?.data ?? []).map((item) =>
        open && isSameInboxItem(item, open)
            ? {
                  ...item,
                  open_count: open.open_count,
                  inbound_count: open.inbound_count,
                  replied_count: open.replied_count ?? item.replied_count,
                  last_message_at: open.last_message_at,
                  last_inbound_at: open.last_inbound_at,
                  last_message: (isMessagesView.value ? newestDirectMessage.value : null) ?? item.last_message,
              }
            : item,
    );
});
const isStoredConversation = (item: InboxPostConversation): item is InboxConversationData => item.id !== null;

/** Direct message conversations always exist; only By post lists posts without one. */
const directConversations = computed(() => conversationList.value.filter(isStoredConversation));

const directConversation = computed(() =>
    props.conversation && isStoredConversation(props.conversation) ? props.conversation : null,
);

const messageList = computed(() => props.messages?.data ?? []);
const conversationThreadList = computed(() => props.conversationThreads?.data ?? []);

const {
    hasNewElsewhere,
    hasNewComments,
    isReordering,
    newMessageIds,
    reorder,
    acknowledgeNewComments,
    forgetNewComments,
} = useInboxRealtime({
    view: () => props.view,
    conversation: () => props.conversation,
    messages: () => conversationThreadList.value,
    listMessages: () => messageList.value,
    filters: () => props.filters,
});

watch(
    () => props.conversation?.id,
    () => forgetNewComments(),
);

watch(
    () => [props.view, JSON.stringify(props.filters)] as const,
    () => {
        showsThreadOnMobile.value = false;
    },
);

const isMobile = (): boolean => window.matchMedia('(max-width: 767px)').matches;

const revealConversationOnMobile = async (): Promise<void> => {
    await nextTick();

    if (isMobile()) {
        conversationColumn.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

onMounted(() => {
    if (props.isConversationOpened) {
        revealConversationOnMobile();
    }
});

/** Whether every channel the filters keep has reviews and no comments. */
const isReviewOnly = (channelIds: string[]): boolean => {
    const scope = channelsInScope(channelIds);

    return (
        scope.length > 0 &&
        scope.every(
            (channel) => channel.kinds.includes('review') && !channel.kinds.includes('comment'),
        )
    );
};

/** The network of the picked channels, when they all share one. */
const selectedPlatform = computed((): string | null => {
    const platforms = new Set(
        props.inboxChannels
            .filter((channel) => (props.filters.channel ? [props.filters.channel] : props.filters.channels).includes(channel.id))
            .map((channel) => channel.platform),
    );

    return platforms.size === 1 ? [...platforms][0] : null;
});

const isReviewOnlyScope = computed(() => isReviewOnly(props.filters.channels));

watch(
    () => [props.view, showsViewToggle.value] as const,
    ([view, togglable]) => {
        if (togglable && props.filters.tab === 'comments') {
            rememberCommentsView(view);
        }
    },
    { immediate: true },
);

watch(
    () => [props.view, props.filters.posts] as const,
    ([view, posts]) => {
        if (view === 'post') {
            rememberPosts(posts);
        }
    },
    { immediate: true },
);

const changeFilters = (changed: InboxFiltersData): void => {
    const isBackOnComments = changed.tab === 'comments' && props.filters.tab !== 'comments';
    const view = isBackOnComments ? commentsView() : props.view;
    const filters = isBackOnComments ? { ...changed, posts: rememberedPosts() } : changed;

    if (view === 'post' && filters.channels.length && isReviewOnly(filters.channels)) {
        router.get(inboxViewUrl('list', filters));

        return;
    }

    applyInboxFilters(view, filters);
};

const selectConversation = (conversation: InboxPostConversation): void => {
    if (isMessagesView.value) {
        showsThreadOnMobile.value = true;
    }

    if (isSameInboxItem(conversation, props.conversation)) {
        revealConversationOnMobile();

        return;
    }

    const threadProp = isMessagesView.value ? 'directMessages' : 'conversationThreads';

    router.get(
        conversation.id
            ? conversationUrl(conversation.id, props.filters)
            : inboxPostUrl(conversation.post_id ?? '', props.filters),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            only: ['conversation', threadProp, 'isConversationOpened'],
            reset: [threadProp],
            onSuccess: revealConversationOnMobile,
        },
    );
};

const isDesktop = useMediaQuery('(min-width: 768px)');

const isPostsPanelOpen = ref(false);

/** "Resolve this conversation" only targets a conversation the user can see. */
const bulkResolveConversationId = computed((): string | null => {
    const isVisible =
        isPostView.value ||
        (isMessagesView.value && (isDesktop.value || showsThreadOnMobile.value));

    return isVisible ? (props.conversation?.id ?? null) : null;
});

const togglePostsPanel = (): void => {
    isPostsPanelOpen.value = !isPostsPanelOpen.value;
};

const selectedIndex = computed(() =>
    conversationList.value.findIndex((item) => isSameInboxItem(item, props.conversation)),
);

const page = usePage();

const postList = useTemplateRef<InstanceType<typeof InboxPostList>>('postList');

const hasMoreConversations = computed(() => page.scrollProps?.conversations?.nextPage != null);

const hasNextConversation = computed(
    () =>
        selectedIndex.value !== -1 &&
        (selectedIndex.value < conversationList.value.length - 1 || hasMoreConversations.value),
);

const selectSibling = (direction: -1 | 1): void => {
    const target = selectedIndex.value + direction;
    const sibling = conversationList.value[target];

    if (sibling) {
        selectConversation(sibling);

        return;
    }

    if (direction === 1 && hasMoreConversations.value) {
        postList.value?.loadMore(() => {
            const loaded = conversationList.value[target];

            if (loaded) {
                selectConversation(loaded);
            }
        });
    }
};

const hideReorderHint = (): void => {
    if (!props.showsReorderHint) {
        return;
    }

    router.post(
        dismissReorderHint.url(),
        {},
        { async: true, preserveScroll: true, preserveState: true, preserveUrl: true, only: ['showsReorderHint'] },
    );
};

const reorderPosts = (): void => {
    hideReorderHint();
    reorder();
};

const toggleAllPosts = (): void => {
    changeFilters({ ...props.filters, posts: props.filters.posts === 'all' ? 'commented' : 'all' });
};
</script>

<template>
    <Head :title="$t('inbox.title')" />

    <AppLayout full-width>
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <HeaderTitle :title="$t('inbox.title')" :icon="IconMessages" />
                <Deferred v-if="hasInboxChannels" data="commentScore">
                    <template #fallback>
                        <Skeleton
                            class="size-9 shrink-0 rounded-full"
                            data-testid="inbox-score-skeleton"
                        />
                    </template>
                    <InboxScoreBadge
                        v-if="commentScore"
                        :score="commentScore"
                        :channels="inboxChannels"
                        :channel-ids="filters.channel ? [filters.channel] : filters.channels"
                    />
                </Deferred>
            </div>
        </template>
        <template v-if="hasInboxChannels && showsViewToggle" #header-actions>
            <InboxHeader :view="view" :filters="filters" class="max-md:hidden" />
        </template>

        <div
            v-if="!hasInboxChannels"
            class="flex h-full min-w-0 flex-1 flex-col gap-6 px-4 pt-2 pb-10 md:px-8"
            data-testid="inbox-page"
        >
            <InboxEmptyState :filtered="false" />
        </div>

        <div
            v-else
            class="flex min-h-0 min-w-0 flex-1 flex-col"
            data-testid="inbox-page"
        >
            <InboxFilters
                :filters="filters"
                :channels="inboxChannels"
                :filter-channels="filterChannels"
                :counts="tabCounts"
                :tabs-only="showsMentionsAvailability"
                @change="changeFilters"
            >
                <template v-if="canReply" #actions>
                    <InboxBulkResolveMenu
                        :channel-ids="filters.channels"
                        :conversation-id="bulkResolveConversationId"
                        :tab="filters.tab"
                    />
                </template>
                <template v-if="showsViewToggle" #view>
                    <InboxHeader :view="view" :filters="filters" class="md:hidden" />
                </template>
            </InboxFilters>

            <div
                v-if="hasBanners"
                class="flex flex-col gap-2 px-4 pt-4 md:px-8"
            >
                <InboxReconnectBanner
                    v-for="channel in channelsWithoutAccess"
                    :key="channel.id"
                    :channel="channel"
                    :tab="filters.tab"
                />
                <InboxReconnectBanner
                    v-for="channel in channelsWithoutMentionAccess"
                    :key="`mentions-${channel.id}`"
                    :channel="channel"
                    mentions
                />
                <InboxReconnectBanner
                    v-for="channel in channelsWithoutMessageAccess"
                    :key="`messages-${channel.id}`"
                    :channel="channel"
                    messages
                />
                <InboxSyncErrorBanner
                    v-for="{ channel, error } in channelsWithSyncError"
                    :key="`sync-${channel.id}-${error.kind}`"
                    :channel="channel"
                    :error="error"
                    :tab="filters.tab"
                    @dismiss="syncErrors.dismiss"
                />
            </div>

            <div
                v-if="showsMentionsAvailability"
                class="min-h-0 flex-1 overflow-y-auto px-4"
                data-testid="inbox-list"
            >
                <InboxMentionsAvailability :networks="mentionsAvailability" />
            </div>

            <div
                v-else-if="isMessagesView"
                class="flex min-h-0 flex-1 flex-col md:flex-row"
                data-testid="inbox-messages"
            >
                <InboxListeningState
                    v-if="!hasConversations"
                    class="flex-1 px-4 md:px-8"
                    :channels="inboxChannels"
                    :backfill-days="backfillDays"
                    messages
                />
                <InboxEmptyState
                    v-else-if="conversationList.length === 0"
                    :filtered="true"
                    messages
                    class="px-4 pt-10 md:px-8"
                />
                <template v-else>
                    <InboxDirectList
                        class="w-full shrink-0 px-4 pt-4 max-md:min-h-0 max-md:flex-1 md:w-[300px] md:border-e md:border-border md:ps-8 md:pe-4"
                        :class="showsThreadOnMobile ? 'max-md:hidden' : ''"
                        :conversations="directConversations"
                        :selected-id="conversation?.id ?? null"
                        :has-new="hasNewElsewhere"
                        :reordering="isReordering"
                        @select="selectConversation"
                        @show-new="reorder"
                    />
                    <div
                        ref="conversationColumn"
                        class="flex min-h-0 min-w-0 flex-1 flex-col"
                        :class="showsThreadOnMobile ? '' : 'max-md:hidden'"
                        data-testid="inbox-dm-pane"
                    >
                        <InboxDirectThread
                            v-if="directConversation"
                            :key="directConversation.id"
                            :conversation="directConversation"
                            :messages="directMessageList"
                            :channel="conversationChannel"
                            :can-reply="canReply"
                            @back="showConversationList"
                        />
                    </div>
                </template>
            </div>

            <div
                v-else-if="isPostView"
                class="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 md:flex-row md:overflow-visible md:px-8"
                data-testid="inbox-by-post"
            >
                <InboxPostList
                    ref="postList"
                    class="w-full shrink-0 pt-4 max-md:max-h-[50dvh] max-md:pt-[15px] max-md:border-b max-md:border-border max-md:pb-4 md:w-[268px] md:border-e md:border-border md:pe-4"
                    :class="isPostsPanelOpen ? '' : 'max-md:hidden'"
                    :conversations="conversationList"
                    :selected="conversation"
                    :has-new-elsewhere="hasNewElsewhere"
                    :reordering="isReordering"
                    :all-posts="filters.posts === 'all'"
                    :shows-hint="showsReorderHint && (isDesktop || isPostsPanelOpen)"
                    @select="selectConversation"
                    @reorder="reorderPosts"
                    @toggle-all-posts="toggleAllPosts"
                    @dismiss-hint="hideReorderHint"
                    @collapse="togglePostsPanel"
                />
                <div
                    ref="conversationColumn"
                    class="min-w-0 flex-1 scroll-mt-4 pt-4 max-md:flex max-md:flex-col max-md:gap-4 md:min-h-0 md:overflow-y-auto md:ps-4"
                    :class="isPostsPanelOpen ? 'max-md:pt-3' : 'max-md:pt-[15px]'"
                >
                    <InboxPostNavigator
                        v-if="!isPostsPanelOpen && conversation"
                        class="me-10 md:hidden"
                        :conversation="conversation"
                        :has-previous="selectedIndex > 0"
                        :has-next="hasNextConversation"
                        @toggle="togglePostsPanel"
                        @previous="selectSibling(-1)"
                        @next="selectSibling(1)"
                    />
                    <InboxConversation
                        v-if="conversation"
                        :key="conversation.id ?? `post-${conversation.post_id}`"
                        :conversation="conversation"
                        :threads="conversationThreadList"
                        :channel="conversationChannel"
                        :can-reply="canReply"
                        :new-message-ids="newMessageIds"
                        :has-new-comments="hasNewComments"
                        :hides-status-line-on-mobile="!isPostsPanelOpen"
                        @show-new="acknowledgeNewComments"
                    />
                    <InboxListeningState
                        v-else-if="!hasConversations"
                        :channels="inboxChannels"
                        :backfill-days="backfillDays"
                    />
                    <InboxEmptyState v-else :filtered="true" />
                </div>
            </div>

            <div
                v-else
                class="min-h-0 flex-1 overflow-y-auto"
                data-testid="inbox-list"
            >
                <InboxListeningState
                    v-if="!hasConversations"
                    :channels="inboxChannels"
                    :backfill-days="backfillDays"
                    :mentions="isMentionsTab"
                />
                <InboxEmptyState
                    v-else-if="messageList.length === 0 && isFiltered"
                    :filtered="true"
                    :mentions="isMentionsTab"
                />
                <InboxFeed
                    v-else
                    :messages="messageList"
                    :channels="inboxChannels"
                    :can-reply="canReply"
                    :replied-recently-count="repliedRecentlyCount ?? 0"
                    :open-count="(isMentionsTab ? tabCounts.mentions : tabCounts.comments) ?? 0"
                    :mode="isMentionsTab ? 'mentions' : 'comments'"
                    :reviews-only="isReviewOnlyScope"
                    :platform="selectedPlatform"
                    :has-new="hasNewElsewhere"
                    :reordering="isReordering"
                    @show-new="reorder"
                />
            </div>
        </div>
    </AppLayout>
</template>
