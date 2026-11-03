import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import { clamp, filled, transform } from '@/lib/helpers';
import { threadRepliesOf } from '@/lib/threadReplies';
import type {
    PublicationPoll,
    PublicationThreadPoll,
} from '@/types/analytics';
import { ContentType } from '@/types/content-type';
import type { PublishConfig } from '@/types/platform';
import type { PostCard } from '@/types/publish';
import type { XPoll, XPollDuration, XPollLimits } from '@/types/x';

export const MINUTES_PER_HOUR = 60;
export const HOURS_PER_DAY = 24;
const MINUTES_PER_DAY = HOURS_PER_DAY * MINUTES_PER_HOUR;

/** The poll limits in an account's `publishConfig`, or null on a network without polls. */
export const pollLimitsOf = (
    publishConfig: PublishConfig | null | undefined,
): XPollLimits | null => publishConfig?.poll ?? null;

/** The longest a poll runs, in whole days. */
export const pollMaxDays = (limits: XPollLimits): number =>
    Math.floor(limits.maxDuration / MINUTES_PER_DAY);

export const splitPollDuration = (minutes: number): XPollDuration => ({
    days: Math.floor(minutes / MINUTES_PER_DAY),
    hours: Math.floor((minutes % MINUTES_PER_DAY) / MINUTES_PER_HOUR),
    minutes: minutes % MINUTES_PER_HOUR,
});

/** Minutes for the picked parts, within the poll's shortest and longest length; the longest takes no hours or minutes. */
export const joinPollDuration = (
    parts: XPollDuration,
    limits: XPollLimits,
): number => {
    if (parts.days >= pollMaxDays(limits)) return limits.maxDuration;

    const total =
        parts.days * MINUTES_PER_DAY +
        parts.hours * MINUTES_PER_HOUR +
        parts.minutes;

    return clamp(total, limits.minDuration, limits.maxDuration);
};

export const newPoll = (limits: XPollLimits): XPoll => ({
    options: Array.from({ length: limits.minOptions }, () => ''),
    duration_minutes: limits.defaultDuration,
});

/**
 * A choice cut to the longest X accepts, counted as the server counts it: by
 * characters (an emoji is one), without the spaces before it.
 */
export const fitPollChoice = (choice: string, maxLength: number): string => {
    const characters = Array.from(choice);
    const firstCharacter = characters.findIndex((character) =>
        filled(character),
    );

    if (firstCharacter === -1) {
        return choice;
    }

    return characters.slice(0, firstCharacter + maxLength).join('');
};

/** The choices the editor shows: the stored ones, padded to the number a poll starts with. */
export const editablePollOptions = (
    options: string[] | undefined,
    minOptions: number,
): string[] => {
    const stored = Array.isArray(options) ? options.map(String) : [];

    return Array.from(
        { length: Math.max(minOptions, stored.length) },
        (_, index) => stored[index] ?? '',
    );
};

/** The units a poll's length is shown in, largest first, with their minutes. */
const POLL_LENGTH_UNITS = [
    ['day', MINUTES_PER_DAY],
    ['hour', MINUTES_PER_HOUR],
    ['minute', 1],
] as const;

/** The poll's length in its largest whole unit, as X shows it ("1 day", "23 hours", "5 minutes"). */
export const formatPollLength = (minutes: number): string => {
    const [unit, size] =
        POLL_LENGTH_UNITS.find(([, size]) => minutes >= size) ??
        POLL_LENGTH_UNITS[POLL_LENGTH_UNITS.length - 1];

    return new Intl.NumberFormat(activeLocale.value, {
        style: 'unit',
        unit,
        unitDisplay: 'long',
    }).format(Math.floor(minutes / size));
};

/** A published poll before its first read from X: its choices with no votes, open until its length from publishing. */
const unreadPollResult = (
    poll: Partial<XPoll>,
    publishedAt: string,
): PublicationPoll => ({
    options: (poll.options ?? []).map((label) => ({ label, votes: 0 })),
    voting_status: 'open',
    end_datetime: transform(poll.duration_minutes, (minutes) =>
        dayjs.utc(publishedAt).add(minutes, 'minute').toISOString(),
    ),
    duration_minutes: poll.duration_minutes ?? null,
});

/** A post's own poll: only an X poll has one, whatever its meta still holds. */
const ownPollOf = (post: PostCard): Partial<XPoll> | undefined => {
    if (post.content_type !== ContentType.XPoll) {
        return undefined;
    }

    return post.meta?.poll;
};

/** A published post's own polls with no votes yet, as they show before X is read. */
const unreadPollsOf = (
    post: PostCard,
): { poll: PublicationPoll | null; threadPolls: PublicationThreadPoll[] } =>
    transform(
        post.published_at,
        (publishedAt) => ({
            poll: transform(ownPollOf(post), (poll) =>
                unreadPollResult(poll, publishedAt),
            ),
            threadPolls: threadRepliesOf(post.meta).flatMap((reply, index) => {
                if (filled(reply.poll)) {
                    return [
                        {
                            reply: index,
                            ...unreadPollResult(reply.poll, publishedAt),
                        },
                    ];
                }

                return [];
            }),
        }),
        { poll: null, threadPolls: [] },
    );

/**
 * The polls a sent post shows: each one as X returned it at the last read,
 * or, until X has been read for it (about an hour after publishing), with
 * its choices and no votes.
 */
export const sentPollResults = (
    post: PostCard,
): { poll: PublicationPoll | null; threadPolls: PublicationThreadPoll[] } => {
    const read = post.metrics?.available ? post.metrics.publication : null;
    const readReplies = read?.thread_polls ?? [];
    const unread = unreadPollsOf(post);
    const unreadReplies = unread.threadPolls.filter(
        (unreadReply) =>
            !readReplies.some((readReply) => readReply.reply === unreadReply.reply),
    );

    return {
        poll: read?.poll ?? unread.poll,
        threadPolls: [...readReplies, ...unreadReplies].sort(
            (first, second) => first.reply - second.reply,
        ),
    };
};
