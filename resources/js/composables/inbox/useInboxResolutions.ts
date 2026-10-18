import { router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { toast } from 'vue-sonner';

import {
    inboxReloadOptions,
    inboxWriteOptions,
    reloadOpenConversation,
} from '@/lib/inbox';
import {
    destroy as destroyResolution,
    store as storeResolution,
} from '@/routes/app/inbox/resolutions';
import type { InboxMessage, InboxResolveScope, InboxTab } from '@/types/inbox';

const pending = reactive<Record<string, boolean>>({});

const pendingCallbacks = (key: string) => ({
    onStart: () => {
        pending[key] = true;
    },
    onError: (errors: Record<string, string>) => {
        const message = Object.values(errors)[0];

        if (message) {
            toast.error(message);
        }
    },
    onFinish: () => {
        pending[key] = false;
    },
});

export const useInboxResolutions = () => {
    const page = usePage();

    const openConversationId = (): string | null =>
        (page.props.conversation as { id: string } | null)?.id ?? null;

    const isPending = (key: string): boolean => Boolean(pending[key]);

    const toggleResolved = (message: InboxMessage): void => {
        if (isPending(message.id)) {
            return;
        }

        if (message.resolved_at) {
            router.delete(
                destroyResolution.url(message.id),
                inboxWriteOptions(message.id, pendingCallbacks(message.id)),
            );

            return;
        }

        router.post(
            storeResolution.url(),
            { scope: 'thread', message_id: message.id },
            inboxWriteOptions(message.id, pendingCallbacks(message.id)),
        );
    };

    /**
     * Resolving a whole conversation reads it again in place; resolving every
     * or every older message starts the lists over, since rows all over them
     * change at once.
     */
    const resolveMany = (
        scope: Exclude<InboxResolveScope, 'message' | 'thread'>,
        target: {
            conversationId?: string;
            channelIds?: string[];
            tab?: InboxTab;
        } = {},
    ): void => {
        const key = `bulk-${scope}`;

        if (isPending(key)) {
            return;
        }

        const conversationId = openConversationId();
        const callbacks = pendingCallbacks(key);

        router.post(
            storeResolution.url(),
            {
                scope,
                conversation_id: target.conversationId ?? null,
                channels: target.channelIds ?? [],
                tab: target.tab ?? 'comments',
            },
            scope === 'conversation'
                ? inboxWriteOptions(null, callbacks)
                : {
                      async: true,
                      preserveScroll: true,
                      preserveState: true,
                      ...inboxReloadOptions(conversationId),
                      ...callbacks,
                      onSuccess: () => {
                          if (conversationId) {
                              reloadOpenConversation(conversationId);
                          }
                      },
                  },
        );
    };

    return { isPending, toggleResolved, resolveMany };
};
