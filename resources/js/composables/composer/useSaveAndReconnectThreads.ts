import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

import { connectUrl } from '@/composables/useNetworkConnect';
import type { PostComposition } from '@/composables/usePostComposition';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { filled } from '@/lib/helpers';
import { PostStatus } from '@/types/post';

interface SaveAndReconnectThreadsOptions {
    /** The status the edited post had when it opened, if it was saved. */
    status: () => string | undefined;
    /** Saves the post; false when it cannot be saved yet. */
    submit: (
        status: PostComposition['status'],
        onSaved: () => void,
        leavesApp: boolean,
    ) => boolean;
}

interface ReconnectedAccount {
    id: string;
    platform: string;
}

/**
 * Saves the post as a draft, then reconnects its channel, so a thread held
 * back by a permission the channel lacks is kept while the user grants it.
 * The save says it leaves the app, so the page does not step back through
 * history after it, which would cancel the visit to the network's sign-in.
 * An approver is asked to decide on a pending post first, so saving never
 * takes it out of review by accident.
 */
export const useSaveAndReconnectThreads = (options: SaveAndReconnectThreadsOptions) => {
    const { requiresApproval } = useWorkspaceAbilities();

    const reconnect = (account: ReconnectedAccount): void => {
        const url = connectUrl(account.platform, account.id);

        if (filled(url)) {
            window.location.assign(url);
        }
    };

    return (account: ReconnectedAccount): void => {
        if (
            options.status() === PostStatus.PendingApproval &&
            !requiresApproval.value
        ) {
            toast.error(trans('posts.form.thread.reconnect_pending'), {
                testId: 'thread-reconnect-pending',
            });

            return;
        }

        const submitted = options.submit(
            PostStatus.Draft,
            () => reconnect(account),
            true,
        );

        if (!submitted) {
            toast.error(trans('posts.form.thread.reconnect_blocked'), {
                testId: 'thread-reconnect-blocked',
            });
        }
    };
};
