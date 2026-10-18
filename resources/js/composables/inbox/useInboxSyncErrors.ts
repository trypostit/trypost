import { reactive } from 'vue';

import type { InboxChannel, InboxSyncError } from '@/types/inbox';

const dismissed = reactive(new Set<string>());

const keyOf = (channel: InboxChannel, error: InboxSyncError): string => `${channel.id}:${error.kind}:${error.sync_error}`;

/** The per-channel sync banners: "Not now" hides one for the rest of the session. */
export const useInboxSyncErrors = () => {
    const isDismissed = (channel: InboxChannel, error: InboxSyncError): boolean => dismissed.has(keyOf(channel, error));

    const dismiss = (channel: InboxChannel, error: InboxSyncError): void => {
        dismissed.add(keyOf(channel, error));
    };

    return { isDismissed, dismiss };
};
