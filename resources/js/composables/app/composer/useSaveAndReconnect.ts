import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

import { useNetworkConnect } from '@/composables/app/useNetworkConnect';
import { connectUrl } from '@/lib/app/connectRoutes';
import { filled } from '@/lib/helpers';
import { PostStatus } from '@/types/app/post';
import type {
    ComposerAccount,
    SubmitPostComposition,
} from '@/types/app/post-composition';

/** The channel to reconnect: its id and the network whose connect flow it uses. */
type ReconnectedAccount = Pick<ComposerAccount, 'id' | 'platform'>;

/**
 * Saves the post as a draft, then reconnects its channel, so a post held back
 * by a permission the channel lacks (`PostComposerPermissionWarning`) is kept while
 * the user grants it. The save says it leaves the app, so the page does not
 * step back through history after it, which would cancel the visit to the
 * network's sign-in. Its partial response includes errors and, for an edit,
 * the details of a post finalized meanwhile. No deferred props load when the
 * sign-in stores its pending connection in the session. A network that
 * reconnects in place (Telegram's dialog; none of them gates a feature today)
 * has no sign-in to visit, so the post stays open in the composer instead. A
 * post waiting for approval never offers it (`PostComposerPermissionWarning`'s
 * `reconnectable`).
 */
export const useSaveAndReconnect = (submit: SubmitPostComposition) => {
    const { startConnect } = useNetworkConnect([]);

    return (account: ReconnectedAccount): void => {
        const url = connectUrl(account.platform, account.id);

        if (!filled(url)) {
            startConnect(account.platform, account.id);

            return;
        }

        const submitted = submit(
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
