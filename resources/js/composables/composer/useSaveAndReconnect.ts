import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

import { useNetworkConnect } from '@/composables/useNetworkConnect';
import type {
    ComposerAccount,
    PostComposition,
} from '@/composables/usePostComposition';
import { connectUrl } from '@/lib/connectRoutes';
import { filled } from '@/lib/helpers';
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

/** The channel to reconnect: its id and the network whose connect flow it uses. */
type ReconnectedAccount = Pick<ComposerAccount, 'id' | 'platform'>;

/**
 * Saves the post as a draft, then reconnects its channel, so a post held back
 * by a permission the channel lacks (`ChannelPermissionWarning`) is kept while
 * the user grants it. The save says it leaves the app, so the page does not
 * step back through history after it, which would cancel the visit to the
 * network's sign-in. A network that reconnects in place (Telegram's dialog;
 * none of them gates a feature today) has no sign-in to visit, so the post
 * stays open in the composer instead. A pending post is decided on first
 * (only someone who manages channels sees the link, and they approve), so
 * saving never takes it out of review.
 */
export const useSaveAndReconnect = (options: SaveAndReconnectOptions) => {
    const { startConnect } = useNetworkConnect([]);

    return (account: ReconnectedAccount): void => {
        if (options.status() === PostStatus.PendingApproval) {
            toast.error(trans('posts.form.reconnect.pending'), {
                testId: 'reconnect-pending',
            });

            return;
        }

        const url = connectUrl(account.platform, account.id);

        if (!filled(url)) {
            startConnect(account.platform, account.id);

            return;
        }

        const submitted = options.submit(
            PostStatus.Draft,
            () => window.location.assign(url),
            true,
        );

        if (!submitted) {
            toast.error(trans('posts.form.reconnect.blocked'), {
                testId: 'reconnect-blocked',
            });
        }
    };
};
