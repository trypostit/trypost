import { router, usePage } from '@inertiajs/vue3';
import { toRaw } from 'vue';

import { isVideo } from '@/lib/mediaType';
import { inbox as channelInboxIndex } from '@/routes/app/channels';
import {
    conversations as channelInboxConversation,
    messages as channelInboxMessages,
} from '@/routes/app/channels/inbox';
import { index as inboxIndex } from '@/routes/app/inbox';
import { show as showConversation } from '@/routes/app/inbox/conversations';
import { index as inboxMessagesIndex } from '@/routes/app/inbox/messages';
import type {
    InboxChannel,
    InboxConversationKind,
    InboxDateRange,
    InboxDirectContent,
    InboxFilters,
    InboxMessage,
    InboxPostsFilter,
    InboxScrollProp,
    InboxSyncError,
    InboxTab,
    InboxView,
} from '@/types/inbox';
import { Platform } from '@/types/platform';

export const INBOX_RELOAD_KEYS = [
    'conversations',
    'conversation',
    'conversationThreads',
    'directMessages',
    'messages',
    'inbox',
    'inboxChannels',
    'repliedRecentlyCount',
    'tabCounts',
    'hasConversations',
    'commentScore',
];

export const INBOX_SCROLL_KEYS = ['conversations', 'messages', 'directMessages'];

/** Leaving the open post (another post, tab or filters) also starts its comments over. */
export const INBOX_NAVIGATION_SCROLL_KEYS = [...INBOX_SCROLL_KEYS, 'conversationThreads'];

/**
 * Every prop of the inbox page. Inertia sends `reset` keys as partial data, so a
 * filter visit that resets the scroll lists must ask for the whole page too, or
 * the filters, view and counts keep their old values.
 */
const INBOX_PAGE_KEYS = [
    ...INBOX_RELOAD_KEYS,
    'view',
    'filters',
    'isConversationOpened',
    'mentionsAvailability',
    'filterChannels',
    'hasInboxChannels',
    'showsReorderHint',
    'backfillDays',
    'canReply',
];

/** The props of the open post or conversation. */
const OPEN_CONVERSATION_KEYS = ['conversation', 'conversationThreads', 'directMessages'];

/** The counts every view shows, read again after an action without moving any list. */
export const INBOX_COUNT_KEYS = [
    'inbox',
    'inboxChannels',
    'tabCounts',
    'repliedRecentlyCount',
    'hasConversations',
];

/**
 * A reload of every list from its first page, so a row the filters no longer
 * match leaves and new rows land in sort order. Only bulk resolves and an
 * explicit reorder start the lists over; the open post or conversation is left
 * out while one is open (the index route would pick its default one again) and
 * read again by name with `reloadOpenConversation`.
 */
export const inboxReloadOptions = (conversationId: string | null) => ({
    only: conversationId
        ? INBOX_RELOAD_KEYS.filter((key) => !OPEN_CONVERSATION_KEYS.includes(key))
        : INBOX_RELOAD_KEYS,
    reset: conversationId
        ? INBOX_SCROLL_KEYS.filter((key) => !OPEN_CONVERSATION_KEYS.includes(key))
        : INBOX_SCROLL_KEYS,
});

const cancelTokens: Record<string, { cancel: () => void }> = {};

/** Cancels the previous reload of the same kind, so an older response never lands after a newer one. */
const latestOnly = (key: string) => ({
    onCancelToken: (token: { cancel: () => void }) => {
        cancelTokens[key]?.cancel();
        cancelTokens[key] = token;
    },
});

const openConversationId = (): string | null =>
    (usePage().props.conversation as { id: string } | null)?.id ?? null;

/** Reads the counts again on their own, for a write whose response never reached this page. */
export const reloadInboxCounts = (): void => {
    router.reload({ only: INBOX_COUNT_KEYS, ...latestOnly('counts') });
};

const listMessage = (messageId: string): InboxMessage | null =>
    toRaw((usePage().props.messages as InboxScrollProp<InboxMessage> | null)?.data.find((message) => message.id === messageId) ?? null);

/**
 * Reads the List view page holding `messageId` again, in place: the cards merge
 * by id, so the one acted on updates wherever the user scrolled to and every
 * other loaded page stays. Under a status filter, a card that did not come back
 * no longer matches it and leaves the list.
 */
export const reloadListMessage = (messageId: string): void => {
    const before = listMessage(messageId);

    router.reload({
        only: ['messages'],
        data: { messages_around: messageId },
        preserveUrl: true,
        ...latestOnly('messages'),
        onSuccess: (page) => {
            const isFiltered = (page.props.filters as InboxFilters | undefined)?.status !== 'all';

            if (before && isFiltered && listMessage(messageId) === before) {
                router.replaceProp('messages.data', (messages: InboxMessage[]) =>
                    messages.filter((message) => message.id !== messageId),
                );
            }
        },
    });
};

interface InboxWriteCallbacks {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
}

/**
 * The options of a reply, retry, resolve or message. It runs async, so the
 * next click (another post, the next comment, a filter) never cancels it half
 * way, and it reads only the counts: the post, conversation or List page acted
 * on is read again in place afterwards, so no list starts over from page 1. A
 * response for a page the user already left is not applied there, so the counts
 * are read again for the page they are on.
 */
export const inboxWriteOptions = (
    messageId: string | null,
    callbacks: InboxWriteCallbacks = {},
) => {
    const startedOn = usePage().url;
    const conversationId = openConversationId();

    return {
        async: true,
        preserveScroll: true,
        preserveState: true,
        preserveUrl: true,
        only: INBOX_COUNT_KEYS,
        onStart: callbacks.onStart,
        onError: callbacks.onError,
        onFinish: callbacks.onFinish,
        onSuccess: () => {
            callbacks.onSuccess?.();

            if (usePage().url !== startedOn) {
                reloadInboxCounts();
            }

            if (conversationId) {
                if (openConversationId() === conversationId) {
                    reloadOpenConversation(conversationId, messageId);
                }

                return;
            }

            if (messageId && usePage().props.messages) {
                reloadListMessage(messageId);
            }
        },
    };
};

export const DEFAULT_INBOX_FILTERS: InboxFilters = {
    channels: [],
    status: 'all',
    sort: 'unanswered_first',
    range: '12m',
    tab: 'comments',
    posts: 'commented',
};

/** The query string for a set of filters, leaving out the defaults so the URL stays clean. */
export const inboxQuery = (
    filters: InboxFilters,
): Record<string, string | string[]> => {
    const query: Record<string, string | string[]> = {};

    if (filters.channels.length) {
        query.channels = filters.channels;
    }

    (['status', 'sort', 'range', 'tab', 'posts'] as const).forEach((key) => {
        const value = filters[key];

        if (value && value !== DEFAULT_INBOX_FILTERS[key]) {
            query[key] = value;
        }
    });

    return query;
};

/** Whether any filter but the tab differs from the defaults. */
export const isInboxFiltered = (filters: InboxFilters): boolean =>
    filters.channels.length > 0 ||
    (['status', 'sort', 'range'] as const).some(
        (key) => filters[key] !== DEFAULT_INBOX_FILTERS[key],
    );

let lastCommentsView: InboxView = 'post';

/**
 * The view (By post or List) the user last had on Comments, kept for the
 * session so coming back from Mentions or Messages opens it again.
 */
export const rememberCommentsView = (view: InboxView): void => {
    if (view === 'post' || view === 'list') {
        lastCommentsView = view;
    }
};

export const commentsView = (): InboxView => lastCommentsView;

let lastPosts: InboxPostsFilter = DEFAULT_INBOX_FILTERS.posts;

/**
 * Whether By post last showed every post, kept for the session like the
 * view, so coming back from List, Mentions or Messages shows them again.
 */
export const rememberPosts = (posts: InboxPostsFilter): void => {
    lastPosts = posts;
};

export const rememberedPosts = (): InboxPostsFilter => lastPosts;

/** Mentions have no By post view, so they always open the list. */
const showsPostView = (view: InboxView, filters: InboxFilters): boolean =>
    view === 'post' && filters.tab !== 'mentions';

const showsAllPosts = (view: InboxView, filters: InboxFilters): boolean =>
    view === 'post' && filters.tab === 'comments';

/** "Show all posts" only applies to By post, which brings back the last choice. */
const filtersForView = (view: InboxView, filters: InboxFilters): InboxFilters => ({
    ...filters,
    posts: showsAllPosts(view, filters) && (filters.posts === 'all' || lastPosts === 'all') ? 'all' : 'commented',
});

/** By post's page, under the channel on a channel's own inbox. */
const postViewUrl = (filters: InboxFilters, query: Record<string, string | string[]> = {}): string =>
    filters.channel ? channelInboxIndex.url(filters.channel, { query }) : inboxIndex.url({ query });

/** The List view's page, under the channel on a channel's own inbox. */
const listViewUrl = (filters: InboxFilters, query: Record<string, string | string[]> = {}): string =>
    filters.channel ? channelInboxMessages.url(filters.channel, { query }) : inboxMessagesIndex.url({ query });

export const inboxViewUrl = (view: InboxView, filters: InboxFilters): string =>
    showsPostView(view, filters)
        ? postViewUrl(filters, inboxQuery(filtersForView(view, filters)))
        : listViewUrl(filters, inboxQuery(filtersForView(view, filters)));

export const conversationUrl = (
    conversationId: string,
    filters: InboxFilters,
): string =>
    filters.channel
        ? channelInboxConversation.url({ account: filters.channel, conversation: conversationId }, { query: inboxQuery(filters) })
        : showConversation.url(conversationId, { query: inboxQuery(filters) });

/** A post nobody commented on yet has no conversation, so By post opens it by its post. */
export const inboxPostUrl = (postId: string, filters: InboxFilters): string =>
    postViewUrl(filters, { ...inboxQuery(filters), post: postId });

/** The same post card or conversation, even when one side has no conversation yet. */
export const isSameInboxItem = (
    item: { id: string | null; post_id: string | null },
    other: { id: string | null; post_id: string | null } | null,
): boolean => {
    if (!other) {
        return false;
    }

    if (item.id && other.id) {
        return item.id === other.id;
    }

    return item.post_id !== null && item.post_id === other.post_id;
};

/** The key of a post card: its conversation, or its post while it has none. */
export const inboxItemKey = (item: { id: string | null; post_id: string | null }): string =>
    item.id ?? `post-${item.post_id}`;

export const applyInboxFilters = (
    view: InboxView,
    filters: InboxFilters,
): void => {
    const applied = showsAllPosts(view, filters) ? filters : { ...filters, posts: 'commented' as const };

    router.get(
        showsPostView(view, filters) ? postViewUrl(filters) : listViewUrl(filters),
        inboxQuery(applied),
        {
            preserveState: true,
            preserveScroll: true,
            only: INBOX_PAGE_KEYS,
            reset: INBOX_NAVIGATION_SCROLL_KEYS,
        },
    );
};

/**
 * Reads the open post or conversation again, named by `thread_conversation` so
 * the index route keeps it instead of picking its default one: its first page
 * (new comments land on top), then, for an action on a comment, the page
 * holding `aroundMessageId` so its card updates in place wherever the user
 * scrolled to (the comments merge by id). Direct messages merge their newest
 * page by id, so older pages the user loaded stay. If the conversation is gone
 * and another comes back, its comments replace the old ones.
 */
export const reloadOpenConversation = (
    conversationId: string,
    aroundMessageId: string | null = null,
): void => {
    router.reload({
        only: ['conversation', 'conversationThreads', 'directMessages'],
        data: { thread_conversation: conversationId },
        preserveUrl: true,
        ...latestOnly('conversation'),
        onSuccess: (page) => {
            const returned = (page.props.conversation as { id: string } | null)?.id ?? null;

            if (returned && returned !== conversationId) {
                router.reload({
                    only: ['conversationThreads', 'directMessages'],
                    reset: ['conversationThreads', 'directMessages'],
                    data: { thread_conversation: returned },
                    preserveUrl: true,
                    ...latestOnly('conversation'),
                });

                return;
            }

            if (aroundMessageId && page.props.conversationThreads) {
                router.reload({
                    only: ['conversationThreads'],
                    data: { thread_conversation: conversationId, thread_around: aroundMessageId },
                    preserveUrl: true,
                    ...latestOnly('conversation'),
                });
            }
        },
    });
};

/** Every list and count again, and the open post or conversation by name. */
export const reloadInbox = (conversationId: string | null): void => {
    router.reload({
        ...inboxReloadOptions(conversationId),
        onSuccess: () => {
            if (conversationId) {
                reloadOpenConversation(conversationId);
            }
        },
    });
};

export interface InboxThread {
    root: InboxMessage;
    messages: InboxMessage[];
}

const byCreatedAt = (a: InboxMessage, b: InboxMessage): number =>
    (a.platform_created_at ?? '').localeCompare(b.platform_created_at ?? '');

/** Top-level comments newest first, each with its replies oldest first, as a thread reads. */
export const buildThreads = (messages: InboxMessage[]): InboxThread[] => {
    const ids = new Set(messages.map((message) => message.id));
    const roots = messages.filter(
        (message) => !message.parent_id || !ids.has(message.parent_id),
    );
    const rootIds = new Set(roots.map((root) => root.id));
    const repliesByRoot = new Map<string, InboxMessage[]>();

    messages
        .filter((message) => !rootIds.has(message.id))
        .forEach((reply) => {
            const rootId =
                reply.root_id && rootIds.has(reply.root_id)
                    ? reply.root_id
                    : (reply.parent_id ?? '');
            repliesByRoot.set(rootId, [
                ...(repliesByRoot.get(rootId) ?? []),
                reply,
            ]);
        });

    return [...roots].sort((a, b) => byCreatedAt(b, a)).map((root) => ({
        root,
        messages: [root, ...(repliesByRoot.get(root.id) ?? []).sort(byCreatedAt)],
    }));
};

/** A list-view comment with its whole reply tree, threaded like the By post view. */
export const threadOf = (message: InboxMessage): InboxThread =>
    buildThreads([message, ...(message.tree ?? [])]).find(
        (thread) => thread.root.id === message.id,
    ) ?? { root: message, messages: [message] };

export const isInbound = (message: InboxMessage): boolean =>
    message.direction === 'inbound';

export const DATE_RANGE_KEYS: Record<InboxDateRange, string> = {
    '7d': 'last_7_days',
    '30d': 'last_30_days',
    '6m': 'last_6_months',
    '12m': 'last_12_months',
};

/**
 * How a message Meta cannot show is named: Messenger says it expired,
 * Instagram that it is unavailable, as each app does.
 */
export const unavailableMessageKeys = (platform: string): { title: string; body: string } =>
    platform === Platform.Facebook
        ? { title: 'inbox.direct.content.expired_title', body: 'inbox.direct.content.expired_body' }
        : { title: 'inbox.direct.content.unavailable_title', body: 'inbox.direct.content.unavailable_body' };

/** A shared post carries a preview when Meta sent its media or its caption. */
export const hasSharedPreview = (content: InboxDirectContent): boolean =>
    content.type === 'shared_post' && Boolean(content.thumbnail_url || content.title);

/** A shared reel links to `/reel/` or arrives with its video. */
export const isSharedReel = (content: InboxDirectContent): boolean =>
    content.type === 'shared_post' && (isVideo({ type: content.thumbnail_type }) || /\/reels?\//.test(content.url ?? ''));

/**
 * A DM that shows nothing Meta delivered: unsupported content, a share
 * with neither a preview nor a link, or no text, media or content at all.
 * Media still being copied to our storage is on its way, not missing.
 */
export const isUnavailableMessage = (message: InboxMessage): boolean => {
    if (message.removed_at || message.media_pending) {
        return false;
    }

    const content = message.content;

    if (!content) {
        return !message.body && !message.media?.length;
    }

    return content.type === 'unsupported' || (content.type === 'shared_post' && !hasSharedPreview(content) && !content.url);
};

/** A message whose whole text is one link, which the conversation list names instead of printing. */
export const isOnlyLink = (body: string | null | undefined): boolean => /^https?:\/\/\S+$/i.test((body ?? '').trim());

/** The lang key that names a DM without text to show in the conversation list. */
export const directMessagePreviewKey = (message: InboxMessage, platform: string): string => {
    const content = message.content;

    if (message.removed_at) {
        return 'inbox.direct.removed';
    }

    if (isUnavailableMessage(message)) {
        return unavailableMessageKeys(platform).title;
    }

    if (content?.type === 'shared_post') {
        return isSharedReel(content) ? 'inbox.direct.content.shared_reel' : 'inbox.direct.content.shared_post';
    }

    if (content?.type === 'story_reply' || content?.type === 'story_mention') {
        return `inbox.direct.content.${content.type}`;
    }

    if (isOnlyLink(message.body)) {
        return 'inbox.direct.shared_link';
    }

    return 'inbox.direct.attachment';
};

/** Pages and business profiles have a name, not a handle, so they show no @. */
const NAMED_PLATFORMS: string[] = [Platform.Facebook, Platform.GoogleBusiness];

/** How a banner names a channel: its @handle, or the name of a page or business profile. */
export const inboxChannelName = (channel: InboxChannel): string =>
    NAMED_PLATFORMS.includes(channel.platform)
        ? channel.display_name || channel.username
        : `@${channel.username}`;

/** Whether a channel has anything a tab lists: every channel has comments or reviews, not every one has mentions or messages. */
export const channelShowsInTab = (channel: InboxChannel, tab: InboxTab): boolean => {
    if (tab === 'mentions') {
        return channel.supports_mentions;
    }

    if (tab === 'messages') {
        return channel.supports_direct_messages;
    }

    return true;
};

const KIND_TABS: Record<InboxConversationKind, InboxTab> = {
    comment: 'comments',
    review: 'comments',
    mention: 'mentions',
    direct_message: 'messages',
};

/** The kind of the channel whose sync stopped for what a tab lists, if one did. */
export const syncErrorInTab = (channel: InboxChannel, tab: InboxTab): InboxSyncError | null => {
    if (!channelShowsInTab(channel, tab)) {
        return null;
    }

    return (
        (channel.sync_states ?? []).find(
            (state): state is InboxSyncError => state.sync_error !== null && KIND_TABS[state.kind] === tab,
        ) ?? null
    );
};
