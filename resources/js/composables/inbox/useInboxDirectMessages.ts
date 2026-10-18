import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

import { inboxWriteOptions } from '@/lib/inbox';
import { store as storeMessage } from '@/routes/app/inbox/conversations/messages';

const drafts = reactive<Record<string, string>>({});
const sending = reactive<Record<string, boolean>>({});
const errors = reactive<Record<string, string | undefined>>({});

/**
 * Message drafts live here, keyed by the conversation, so a real-time reload
 * of the inbox (or opening another conversation) never loses what was typed.
 */
export const useInboxDirectMessages = () => {
    const draftFor = (conversationId: string, prefill: string): string =>
        drafts[conversationId] ?? prefill;

    const updateDraft = (conversationId: string, text: string): void => {
        drafts[conversationId] = text;
        errors[conversationId] = undefined;
    };

    const isSending = (conversationId: string): boolean => Boolean(sending[conversationId]);

    const errorFor = (conversationId: string): string | undefined => errors[conversationId];

    const send = (conversationId: string, prefill: string, onSent?: () => void): void => {
        if (isSending(conversationId)) {
            return;
        }

        sending[conversationId] = true;
        errors[conversationId] = undefined;

        router.post(
            storeMessage.url(conversationId),
            { text: draftFor(conversationId, prefill) },
            inboxWriteOptions(null, {
                onSuccess: () => {
                    delete drafts[conversationId];
                    onSent?.();
                },
                onError: (validation) => {
                    errors[conversationId] = validation.text ?? validation.conversation;
                },
                onFinish: () => {
                    sending[conversationId] = false;
                },
            }),
        );
    };

    return { draftFor, updateDraft, isSending, errorFor, send };
};
