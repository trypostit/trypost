import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

import { connectUrl } from '@/composables/useNetworkConnect';
import type { PostComposition } from '@/composables/usePostComposition';
import { filled } from '@/lib/helpers';
import type { PlatformValue } from '@/types/platform';
import { PostStatus } from '@/types/post';

interface SaveAndReconnectOptions {
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
    platform: PlatformValue;
}

/**
 * Saves the post as a draft, then reconnects its channel, so a thread held
 * back by a permission the channel lacks is kept while the user grants it.
 * The save says it leaves the app, so the page does not step back through
 * history after it, which would cancel the visit to the network's sign-in.
 * A pending post is decided on first (only someone who manages channels
 * sees the link, and they approve), so saving never takes it out of review.
 */
export const useSaveAndReconnect = (options: SaveAndReconnectOptions) => {
    const reconnect = (account: ReconnectedAccount): void => {
        const url = connectUrl(account.platform, account.id);

        if (filled(url)) {
            window.location.assign(url);
        }
    };

    return (account: ReconnectedAccount): void => {
        if (options.status() === PostStatus.PendingApproval) {
            toast.error(trans('posts.form.reconnect.pending'), {
                testId: 'reconnect-pending',
            });

            return;
        }

        const submitted = options.submit(
            PostStatus.Draft,
            () => reconnect(account),
            true,
        );

        if (!submitted) {
            toast.error(trans('posts.form.reconnect.blocked'), {
                testId: 'reconnect-blocked',
            });
        }
    };
};
