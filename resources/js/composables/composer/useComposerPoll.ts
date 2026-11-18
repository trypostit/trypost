import type {
    ComposerAccount,
    DestinationDraft,
    NetworkGroup,
    usePostComposition,
} from '@/composables/usePostComposition';
import { blank, filled, transform } from '@/lib/helpers';
import { newPoll } from '@/lib/poll';
import { type ThreadReply, withReplyPoll } from '@/lib/threadReplies';
import {
    type ContentTypeValue,
    MEDIALESS_CONTENT_TYPE_REASONS,
    POLL_CONTENT_TYPES,
    POLL_POST_CONTENT_TYPES,
} from '@/types/content-type';
import {
    isReplyPollPlatform,
    THREAD_REPLY_POLL_CONTENT_TYPES,
} from '@/types/network-options';
import type { Poll, PollLimits } from '@/types/poll';

/** What the composer gives the card polls: each card's destination and the override that changes it. */
type ComposerCards = Pick<
    ReturnType<typeof usePostComposition>,
    'resolvedDestination' | 'setGroupOverride'
>;

/** A card's meta as the composer keeps it: what the post sends as `meta`. */
type CardMeta = Record<string, any>;

/** What the composer's thread gives the reply polls: the reply being edited, the replies, a reply's text limit, and whether a reply's file is still uploading. */
export interface ComposerThread {
    activeReply: (group: NetworkGroup) => ThreadReply | undefined;
    replies: (group: NetworkGroup) => ThreadReply[];
    setReplies: (group: NetworkGroup, replies: ThreadReply[]) => void;
    replyLimit: (group: NetworkGroup) => number | null;
    uploading: (group: NetworkGroup, reply: ThreadReply) => boolean;
}

/**
 * The polls of each composer card (X, Mastodon and Threads): the post's
 * (picking Poll starts one, editing writes it into the card's meta, and any
 * other type drops it, as the server does) and each thread reply's (the
 * toolbar's poll button).
 */
export const useComposerPoll = (
    composition: ComposerCards,
    pollLimits: (account: ComposerAccount) => PollLimits | null,
    thread: ComposerThread,
) => {
    const destination = (group: NetworkGroup): DestinationDraft =>
        composition.resolvedDestination(group.anchor);

    /** The card's poll limits, or null on a network without polls. */
    const groupPollLimits = (group: NetworkGroup): PollLimits | null =>
        pollLimits(group.anchor);

    /** An account's text limit for its post type: a poll's question keeps the poll's cap, even with long posts. */
    const accountTextLimit = (
        account: ComposerAccount,
        limit: number | undefined,
    ): number | undefined => {
        const questionCap = pollLimits(account)?.questionMaxLength;
        const isPoll = POLL_CONTENT_TYPES.has(
            composition.resolvedDestination(account).content_type,
        );

        if (isPoll && filled(limit) && filled(questionCap)) {
            return Math.min(limit, questionCap);
        }

        return limit;
    };

    /**
     * Each card's meta from before a poll was added, put back as it was when
     * the poll goes and nothing else changed: a post stored without meta
     * arrives as `[]` (PHP's empty array), and putting back `{}` instead would
     * count as an unsaved change.
     */
    const metaBeforePoll = new Map<string, CardMeta>();
    /**
     * Each card's meta, poll included, from before another type was picked:
     * picking Poll again brings the poll back, and the very same object when
     * nothing else changed, so its keys keep their order and the switch counts
     * as no change.
     */
    const metaBeforeOtherType = new Map<string, CardMeta>();

    const isPollGroup = (group: NetworkGroup): boolean =>
        POLL_CONTENT_TYPES.has(destination(group).content_type);

    /** The card's poll, or an empty one before Poll is picked. */
    const groupPoll = (group: NetworkGroup): Partial<Poll> => {
        const poll = destination(group).meta?.poll;

        if (!filled(poll)) {
            return {};
        }

        return poll;
    };

    const setGroupPoll = (group: NetworkGroup, poll: Poll): void => {
        composition.setGroupOverride(group.key, 'meta', {
            ...destination(group).meta,
            poll,
        });
    };

    /**
     * Whether two metas hold the same values in the same key order. A meta
     * stored empty arrives as `[]` (PHP's empty array) and counts as `{}`.
     */
    const sameMeta = (
        first: CardMeta,
        second: CardMeta,
    ): boolean => JSON.stringify({ ...first }) === JSON.stringify({ ...second });

    /** Takes a card's saved meta out of `saved`, so it is used once. */
    const takeSaved = (
        saved: Map<string, CardMeta>,
        group: NetworkGroup,
    ): CardMeta | undefined => {
        const meta = saved.get(group.key);
        saved.delete(group.key);

        return meta;
    };

    /** The card's last poll back on its meta: the very same object when nothing else changed. */
    const restorePoll = (
        previous: CardMeta,
        current: CardMeta,
    ): CardMeta => {
        const { poll, ...previousMeta } = previous;

        if (sameMeta(previousMeta, current)) {
            return previous;
        }

        return { ...current, poll };
    };

    /** Picking Poll: the card's last poll comes back, else a new one starts. */
    const metaWithPoll = (
        group: NetworkGroup,
        current: CardMeta,
    ): CardMeta => {
        metaBeforePoll.set(group.key, current);
        const previous = takeSaved(metaBeforeOtherType, group);

        if (filled(previous)) {
            return restorePoll(previous, current);
        }

        return { ...current, poll: transform(groupPollLimits(group), newPoll, {}) };
    };

    /** Picking another type: the poll goes, and the meta from before it comes back when nothing else changed. */
    const metaWithoutPoll = (
        group: NetworkGroup,
        current: CardMeta,
        meta: CardMeta,
    ): CardMeta => {
        metaBeforeOtherType.set(group.key, current);
        const before = takeSaved(metaBeforePoll, group);

        if (before !== undefined && sameMeta(before, meta)) {
            return before;
        }

        return meta;
    };

    /**
     * The meta a card takes when its type becomes `contentType`: Poll adds a
     * poll, any other type drops it. Null when the meta stays as it is.
     */
    const pollMetaForContentType = (
        group: NetworkGroup,
        contentType: ContentTypeValue,
    ): CardMeta | null => {
        const current = destination(group).meta ?? {};
        const { poll, ...meta } = current;
        const hasPoll = poll !== undefined;
        const picksPoll = POLL_CONTENT_TYPES.has(contentType);

        if (picksPoll && !hasPoll) {
            return metaWithPoll(group, current);
        }

        if (!picksPoll && hasPoll) {
            return metaWithoutPoll(group, current, meta);
        }

        return null;
    };

    /** Forgets the card's last poll, so picking Poll again starts a new one. */
    const forgetGroupPoll = (group: NetworkGroup): void => {
        metaBeforeOtherType.delete(group.key);
    };

    /** Picks the card's post type, adding or dropping its poll with it. */
    const setGroupContentType = (
        group: NetworkGroup,
        contentType: ContentTypeValue,
    ): void => {
        const meta = pollMetaForContentType(group, contentType);
        composition.setGroupOverride(group.key, 'content_type', contentType);

        if (meta !== null) {
            composition.setGroupOverride(group.key, 'meta', meta);
        }
    };

    /** The card's Remove poll: back to the network's plain post, and picking Poll again starts a new one. */
    const removeGroupPoll = (group: NetworkGroup): void => {
        const postType =
            POLL_POST_CONTENT_TYPES[destination(group).content_type];

        if (filled(postType)) {
            setGroupContentType(group, postType);
        }

        forgetGroupPoll(group);
    };

    /** Forgets every card's last poll and earlier meta: the per-network edits were discarded. */
    const forgetPolls = (): void => {
        metaBeforePoll.clear();
        metaBeforeOtherType.clear();
    };

    /** A reply poll's question keeps the poll's cap (X), even on an account with long posts; no cap elsewhere. */
    const replyPollLimit = (group: NetworkGroup): number => {
        const cap = groupPollLimits(group)?.questionMaxLength;

        if (!filled(cap)) {
            return Infinity;
        }

        return cap;
    };

    /** A reply's text limit: a poll's question keeps the poll's cap, even on an account with long posts. */
    const replyTextLimit = (
        group: NetworkGroup,
        reply: ThreadReply,
    ): number | null => {
        const limit = thread.replyLimit(group);

        if (filled(limit) && filled(reply.poll)) {
            return Math.min(limit, replyPollLimit(group));
        }

        return limit;
    };

    const setReplyPoll = (
        group: NetworkGroup,
        replyKey: string,
        poll: Poll | undefined,
    ): void => {
        const replies = thread.replies(group).map((reply) => {
            if (reply.key !== replyKey) {
                return reply;
            }

            return withReplyPoll(reply, poll);
        });

        thread.setReplies(group, replies);
    };

    /** The poll button shows on a reply that is not a poll yet, on a network whose replies can be polls. */
    const replyPollAvailable = (group: NetworkGroup): boolean => {
        const reply = thread.activeReply(group);

        if (!filled(reply) || filled(reply.poll)) {
            return false;
        }

        if (!isReplyPollPlatform(group.platform)) {
            return false;
        }

        return filled(groupPollLimits(group));
    };

    /** Media in the reply (or on its way) keeps the poll button off, saying why. */
    const replyPollDisabledReasonKey = (group: NetworkGroup): string | null => {
        const reply = thread.activeReply(group);

        if (!filled(reply) || !isReplyPollPlatform(group.platform)) {
            return null;
        }

        if (blank(reply.media) && !thread.uploading(group, reply)) {
            return null;
        }

        const reason =
            MEDIALESS_CONTENT_TYPE_REASONS[
                THREAD_REPLY_POLL_CONTENT_TYPES[group.platform]
            ];

        if (!filled(reason)) {
            return null;
        }

        return reason;
    };

    const addReplyPoll = (group: NetworkGroup): void => {
        const reply = thread.activeReply(group);
        const limits = groupPollLimits(group);

        if (filled(reply) && filled(limits)) {
            setReplyPoll(group, reply.key, newPoll(limits));
        }
    };

    return {
        groupPollLimits,
        accountTextLimit,
        isPollGroup,
        groupPoll,
        setGroupPoll,
        setGroupContentType,
        removeGroupPoll,
        forgetPolls,
        replyPollLimit,
        replyTextLimit,
        setReplyPoll,
        replyPollAvailable,
        replyPollDisabledReasonKey,
        addReplyPoll,
    };
};
