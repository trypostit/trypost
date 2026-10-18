import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

import { inboxWriteOptions } from '@/lib/inbox';
import {
    retry as retryReply,
    store as storeReply,
} from '@/routes/app/inbox/replies';

const drafts = reactive<Record<string, string>>({});
const sending = reactive<Record<string, boolean>>({});
const errors = reactive<Record<string, string | undefined>>({});

/**
 * Reply drafts live here, keyed by the message they answer, so a real-time
 * reload of the inbox (or opening another comment) never loses what was typed.
 */
export const useInboxReplies = () => {
    const draftFor = (messageId: string, prefill: string): string =>
        drafts[messageId] ?? prefill;

    const updateDraft = (messageId: string, text: string): void => {
        drafts[messageId] = text;
        errors[messageId] = undefined;
    };

    const isSending = (messageId: string): boolean => Boolean(sending[messageId]);

    const errorFor = (messageId: string): string | undefined => errors[messageId];

    const post = (
        messageId: string,
        text: string,
        onSent?: () => void,
    ): void => {
        sending[messageId] = true;
        errors[messageId] = undefined;

        router.post(
            storeReply.url(messageId),
            { text },
            inboxWriteOptions(messageId, {
                onSuccess: () => {
                    delete drafts[messageId];
                    onSent?.();
                },
                onError: (validation) => {
                    errors[messageId] = validation.text;
                },
                onFinish: () => {
                    sending[messageId] = false;
                },
            }),
        );
    };

    const send = (
        messageId: string,
        prefill: string,
        onSent?: () => void,
    ): void => {
        const text = draftFor(messageId, prefill);

        if (isSending(messageId)) {
            return;
        }

        post(messageId, text, onSent);
    };

    const retry = (failedId: string): void => {
        if (isSending(failedId)) {
            return;
        }

        sending[failedId] = true;
        errors[failedId] = undefined;

        router.post(
            retryReply.url(failedId),
            {},
            inboxWriteOptions(failedId, {
                onError: (validation) => {
                    errors[failedId] = validation.message;
                },
                onFinish: () => {
                    sending[failedId] = false;
                },
            }),
        );
    };

    return { draftFor, updateDraft, isSending, errorFor, send, retry };
};
