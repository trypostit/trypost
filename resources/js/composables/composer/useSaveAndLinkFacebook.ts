import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { PostStatus } from '@/types/post';
import type { SubmitPostComposition } from '@/types/post-composition';

interface SaveAndLinkFacebookOptions {
    /** The status the edited post had when it opened, if it was saved. */
    status: () => string | undefined;
    /** Whether the composer is set to publish now rather than at a time or in the queue. */
    publishesNow: () => boolean;
    submit: SubmitPostComposition;
}

/**
 * Saves the post, then opens the step that reconnects an Instagram account
 * through Facebook (music needs it). A scheduled post stays scheduled and a
 * pending request stays pending; anything else is saved as a draft. An
 * approver is asked to decide on a pending post first, so saving never
 * approves it by accident.
 */
export const useSaveAndLinkFacebook = (options: SaveAndLinkFacebookOptions) => {
    const { requiresApproval } = useWorkspaceAbilities();
    const { openAt } = useConnectChannelDialog();

    return (accountId: string): void => {
        const status = options.status();

        if (status === PostStatus.PendingApproval && !requiresApproval.value) {
            toast.error(trans('posts.form.instagram.music.save_and_link_pending'), {
                testId: 'instagram-music-save-and-link-pending',
            });

            return;
        }

        const keepsSchedule =
            (status === PostStatus.Scheduled ||
                status === PostStatus.PendingApproval) &&
            !options.publishesNow();

        const submitted = options.submit(
            keepsSchedule ? PostStatus.Scheduled : PostStatus.Draft,
            () => openAt('instagram-facebook', accountId),
        );

        if (!submitted) {
            toast.error(trans('posts.form.instagram.music.save_and_link_blocked'), {
                testId: 'instagram-music-save-and-link-blocked',
            });
        }
    };
};
