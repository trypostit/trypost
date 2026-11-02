import type {
    NetworkGroup,
    usePostComposition,
} from '@/composables/usePostComposition';
import { newPoll, type XPoll, type XPollLimits } from '@/lib/xPoll';
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
    pollLimits: (group: NetworkGroup) => XPollLimits | null,
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

    /**
     * The meta a card takes when its type becomes `contentType`: Poll starts
     * a poll (or brings the last one back), any other type drops it. Null
     * when the meta stays as it is.
     */
    const pollMetaForContentType = (
        group: NetworkGroup,
        contentType: string,
    ): Record<string, any> | null => {
        const current = destination(group).meta ?? {};
        const { poll, ...meta } = current;

        if (contentType === ContentType.XPoll) {
            if (poll === undefined) {
                metaBeforePoll.set(group.key, current);
            }
            const limits = pollLimits(group);
            const restored =
                poll ??
                pollBeforeOtherType.get(group.key) ??
                (limits ? newPoll(limits) : {});
            pollBeforeOtherType.delete(group.key);

            return { ...meta, poll: restored };
        }

        if (poll === undefined) {
            return null;
        }

        pollBeforeOtherType.set(group.key, poll);
        const before = metaBeforePoll.get(group.key);
        metaBeforePoll.delete(group.key);

        return before !== undefined &&
            JSON.stringify({ ...before }) === JSON.stringify(meta)
            ? before
            : meta;
    };

    /** Forgets the card's last poll, so picking Poll again starts a new one. */
    const forgetGroupPoll = (group: NetworkGroup): void => {
        pollBeforeOtherType.delete(group.key);
    };

    return {
        isPollGroup,
        groupPoll,
        setGroupPoll,
        pollMetaForContentType,
        forgetGroupPoll,
    };
};
