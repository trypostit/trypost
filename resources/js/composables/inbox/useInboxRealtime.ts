import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import debounce from '@/debounce';
import {
    INBOX_COUNT_KEYS,
    reloadInbox,
    reloadInboxCounts,
    reloadListMessage,
    reloadOpenConversation,
} from '@/lib/inbox';
import type {
    InboxConversationKind,
    InboxFilters,
    InboxMessage,
    InboxPostConversation,
    InboxTab,
    InboxView,
} from '@/types/inbox';

interface InboxUpdatedPayload {
    conversation_id: string | null;
    kind?: InboxConversationKind | null;
    channel_id?: string | null;
}

const TAB_KINDS: Record<InboxTab, InboxConversationKind[]> = {
    comments: ['comment', 'review'],
    mentions: ['mention'],
    messages: ['direct_message'],
};

/** Whether a change belongs to the tab and channels on screen. */
const isOnScreen = (payload: InboxUpdatedPayload, filters: InboxFilters): boolean =>
    (!payload.kind || TAB_KINDS[filters.tab].includes(payload.kind))
    && (!payload.channel_id || ((!filters.channel || filters.channel === payload.channel_id)
        && (filters.channels.length === 0 || filters.channels.includes(payload.channel_id))));

const RELOAD_DELAY_MS = 1000;

/** The inbound ids of the loaded threads, roots and replies. */
const inboundIds = (threads: InboxMessage[]): Set<string> =>
    new Set(
        threads
            .flatMap((root) => [root, ...(root.tree ?? [])])
            .filter((message) => message.direction === 'inbound')
            .map((message) => message.id),
    );

/** The list each view starts over when the user asks for what arrived. */
const REORDER_KEYS: Record<InboxView, string> = {
    post: 'conversations',
    messages: 'conversations',
    list: 'messages',
};

/**
 * Real-time inbox updates: what changed on the open post or
 * conversation, or on a List card already loaded, is read again in place; what
 * arrived anywhere else only refreshes the counts and flags the reorder button
 * or the "new" pill, so no list moves under the user or drops the pages they
 * scrolled to. Bursts are merged into one reload.
 */
export const useInboxRealtime = (options: {
    view: () => InboxView;
    conversation: () => InboxPostConversation | null;
    messages: () => InboxMessage[];
    listMessages: () => InboxMessage[];
    filters: () => InboxFilters;
}) => {
    const hasNewElsewhere = ref(false);
    const hasNewComments = ref(false);
    const isReordering = ref(false);
    const newMessageIds = ref<string[]>([]);

    let pendingSelected = false;
    let pendingFull = false;
    const pendingListConversations = new Set<string>();

    /** The first loaded List card of a conversation, whose page is read again in place. */
    const loadedListMessage = (conversationId: string): InboxMessage | null =>
        options.listMessages().find((message) => message.conversation?.id === conversationId) ?? null;

    const reloadSelected = (openId: string): void => {
        const known = inboundIds(options.messages());

        router.reload({
            only: ['conversation', 'conversationThreads', ...INBOX_COUNT_KEYS],
            data: { thread_conversation: openId },
            preserveUrl: true,
            onSuccess: (page) => {
                const returned = (page.props.conversation as { id: string } | null)?.id ?? null;

                if (returned && returned !== openId) {
                    router.reload({
                        only: ['conversationThreads', 'directMessages'],
                        reset: ['conversationThreads', 'directMessages'],
                        data: { thread_conversation: returned },
                        preserveUrl: true,
                    });

                    return;
                }

                const arrived = [...inboundIds(options.messages())].filter(
                    (id) => !known.has(id),
                );

                if (arrived.length > 0) {
                    newMessageIds.value = [...newMessageIds.value, ...arrived];
                    hasNewComments.value = true;
                }
            },
        });
    };

    const flush = debounce(() => {
        const view = options.view();
        const full = pendingFull;
        const selected = pendingSelected;
        const listConversations = [...pendingListConversations];
        pendingFull = false;
        pendingSelected = false;
        pendingListConversations.clear();

        const openId = options.conversation()?.id ?? null;

        if (view === 'post') {
            if (full) {
                reloadInbox(openId);

                return;
            }

            if (selected && openId) {
                reloadSelected(openId);

                return;
            }

            reloadInboxCounts();

            return;
        }

        reloadInboxCounts();

        if (view === 'messages' && selected && openId) {
            reloadOpenConversation(openId);
        }

        listConversations
            .map(loadedListMessage)
            .forEach((message) => {
                if (message) {
                    reloadListMessage(message.id);
                }
            });
    }, RELOAD_DELAY_MS);

    useWorkspaceEcho<InboxUpdatedPayload>('.inbox.updated', (payload) => {
        const view = options.view();
        const selectedId = options.conversation()?.id ?? null;

        if (!payload.conversation_id) {
            pendingFull = true;

            if (view !== 'post') {
                hasNewElsewhere.value = true;
            }
        } else if (payload.conversation_id === selectedId) {
            pendingSelected = true;
        } else if (view === 'list' && loadedListMessage(payload.conversation_id)) {
            pendingListConversations.add(payload.conversation_id);
        } else if (isOnScreen(payload, options.filters())) {
            hasNewElsewhere.value = true;
        }

        flush();
    });

    onBeforeUnmount(() => flush.cancel());

    const reorder = (): void => {
        const list = REORDER_KEYS[options.view()];

        hasNewElsewhere.value = false;
        isReordering.value = true;
        router.reload({
            only: [list, ...INBOX_COUNT_KEYS],
            reset: [list],
            onFinish: () => {
                isReordering.value = false;
            },
        });
    };

    const acknowledgeNewComments = (): void => {
        hasNewComments.value = false;
    };

    const forgetNewComments = (): void => {
        hasNewComments.value = false;
        newMessageIds.value = [];
    };

    return {
        hasNewElsewhere,
        hasNewComments,
        isReordering,
        newMessageIds,
        reorder,
        acknowledgeNewComments,
        forgetNewComments,
    };
};
