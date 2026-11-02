import type {
    NetworkGroup,
    usePostComposition,
} from '@/composables/usePostComposition';
import { newPoll, type XPoll } from '@/lib/xPoll';
import { ContentType } from '@/types/content-type';

type Composition = Pick<
    ReturnType<typeof usePostComposition>,
    'resolvedDestination' | 'setGroupOverride'
>;

/**
 * The X poll of each composer card: picking Poll starts one, editing writes
 * it into the card's meta, and any other type drops it, as the server does.
 */
export const useComposerPoll = (
    composition: Composition,
    isEditingReply: (group: NetworkGroup) => boolean,
) => {
    const destination = (group: NetworkGroup) =>
        composition.resolvedDestination(group.anchor);

    /**
     * Each card's meta from before a poll was added, put back as it was when
     * the poll goes and nothing else changed: a post stored without meta
     * arrives as `[]` (PHP's empty array), and putting back `{}` instead would
     * count as an unsaved change.
     */
    const metaBeforePoll = new Map<string, Record<string, any>>();
    /** Each card's poll from before another type was picked, brought back when Poll is picked again. */
    const pollBeforeOtherType = new Map<string, Partial<XPoll>>();

    const isPollGroup = (group: NetworkGroup): boolean =>
        destination(group).content_type === ContentType.XPoll;

    const groupPoll = (group: NetworkGroup): Partial<XPoll> =>
        destination(group).meta?.poll ?? {};

    const setGroupPoll = (group: NetworkGroup, poll: XPoll): void => {
        composition.setGroupOverride(group.key, 'meta', {
            ...destination(group).meta,
            poll,
        });
    };

    const setGroupContentType = (
        group: NetworkGroup,
        contentType: string,
    ): void => {
        const current = destination(group).meta ?? {};
        const { poll, ...meta } = current;
        composition.setGroupOverride(group.key, 'content_type', contentType);

        if (contentType === ContentType.XPoll) {
            if (poll === undefined) {
                metaBeforePoll.set(group.key, current);
            }
            composition.setGroupOverride(group.key, 'meta', {
                ...meta,
                poll: poll ?? pollBeforeOtherType.get(group.key) ?? newPoll(),
            });
            pollBeforeOtherType.delete(group.key);
        } else if (poll !== undefined) {
            pollBeforeOtherType.set(group.key, poll);
            const before = metaBeforePoll.get(group.key);
            metaBeforePoll.delete(group.key);
            composition.setGroupOverride(
                group.key,
                'meta',
                before !== undefined &&
                    JSON.stringify({ ...before }) === JSON.stringify(meta)
                    ? before
                    : meta,
            );
        }
    };

    const removeGroupPoll = (group: NetworkGroup): void => {
        setGroupContentType(group, ContentType.XPost);
        pollBeforeOtherType.delete(group.key);
    };

    const groupMediaDisabledReasonKey = (group: NetworkGroup): string =>
        isPollGroup(group) && !isEditingReply(group)
            ? 'posts.form.x.poll.attachments'
            : 'posts.composer.media_not_supported';

    return {
        isPollGroup,
        groupPoll,
        setGroupPoll,
        setGroupContentType,
        removeGroupPoll,
        groupMediaDisabledReasonKey,
    };
};
