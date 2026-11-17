import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import { clamp, filled, transform } from '@/lib/helpers';
import { threadRepliesOf } from '@/lib/threadReplies';
import type {
    PublicationPoll,
    PublicationThreadPoll,
} from '@/types/analytics';
import { POLL_CONTENT_TYPES } from '@/types/content-type';
import type { PublishConfig } from '@/types/platform';
import type { Poll, PollDuration, PollLimits } from '@/types/poll';
import type { PostCard } from '@/types/publish';

export const MINUTES_PER_HOUR = 60;
export const HOURS_PER_DAY = 24;
const MINUTES_PER_DAY = HOURS_PER_DAY * MINUTES_PER_HOUR;

/** The poll limits in an account's `publishConfig`, or null on a network without polls. */
export const pollLimitsOf = (
    publishConfig: PublishConfig | null | undefined,
): PollLimits | null => publishConfig?.poll ?? null;

/** Whether the author picks how long the poll runs (not on Threads, which sets it). */
export const pollHasDuration = (limits: PollLimits): boolean =>
    filled(limits.maxDuration);

/** The longest a poll runs, in whole days. */
export const pollMaxDays = (limits: PollLimits): number => {
    const longest = limits.maxDuration;

    if (!filled(longest)) {
        return 0;
    }

    return Math.floor(longest / MINUTES_PER_DAY);
};

export const splitPollDuration = (minutes: number): PollDuration => ({
    days: Math.floor(minutes / MINUTES_PER_DAY),
    hours: Math.floor((minutes % MINUTES_PER_DAY) / MINUTES_PER_HOUR),
    minutes: minutes % MINUTES_PER_HOUR,
});

/** Minutes for the picked parts, within the poll's shortest and longest length; the longest takes no hours or minutes. */
export const joinPollDuration = (
    parts: PollDuration,
    limits: PollLimits,
): number => {
    const { minDuration, maxDuration } = limits;

    if (!filled(minDuration) || !filled(maxDuration)) {
        return 0;
    }

    if (parts.days >= pollMaxDays(limits)) {
        return maxDuration;
    }

    const total =
        parts.days * MINUTES_PER_DAY +
        parts.hours * MINUTES_PER_HOUR +
        parts.minutes;

    return clamp(total, minDuration, maxDuration);
};

/** A poll's length: the stored one, else the network's default (0 where the network sets it). */
export const pollDurationOf = (
    poll: Partial<Poll>,
    limits: PollLimits,
): number => {
    if (filled(poll.duration_minutes)) {
        return poll.duration_minutes;
    }

    if (filled(limits.defaultDuration)) {
        return limits.defaultDuration;
    }

    return 0;
};

/** A new poll: empty choices, and the network's default length where the author picks one. */
export const newPoll = (limits: PollLimits): Poll => {
    const options = Array.from({ length: limits.minOptions }, () => '');

    const length = limits.defaultDuration;

    if (!filled(length)) {
        return { options };
    }

    return { options, duration_minutes: length };
};

/**
 * A choice's length as X counts it, without the spaces around it: in UTF-16
 * units (a string's `length`), so an emoji like 🐧 counts two, as on the server
 * (`Poll::choiceLength()`).
 */
export const pollChoiceLength = (choice: string): number => choice.trim().length;

/**
 * A choice cut to the longest X accepts, counted as X counts it
 * (`pollChoiceLength`): the last character goes until it fits, so a character
 * is never split and the spaces before the choice stay.
 */
export const fitPollChoice = (choice: string, maxLength: number): string => {
    let characters = Array.from(choice);

    while (pollChoiceLength(characters.join('')) > maxLength) {
        characters = characters.slice(0, -1);
    }

    return characters.join('');
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

/**
 * A poll before X has been read for it: its choices with no votes. Once its
 * post is published it is open until its length from publishing; before
 * that, voting has not started and has no end.
 */
const unreadPollResult = (
    poll: Partial<Poll>,
    publishedAt: string | null,
): PublicationPoll => ({
    options: (poll.options ?? []).map((label) => ({ label, votes: 0 })),
    voting_status: transform(publishedAt, (): 'open' => 'open'),
    end_datetime: transform(publishedAt, (at) =>
        transform(poll.duration_minutes, (minutes) =>
            dayjs.utc(at).add(minutes, 'minute').toISOString(),
        ),
    ),
    duration_minutes: poll.duration_minutes ?? null,
});

/** A post's own poll: only a poll type has one, whatever its meta still holds. */
export const pollOf = (
    contentType: string | null | undefined,
    meta: Record<string, any> | null | undefined,
): Partial<Poll> | undefined => {
    if (!filled(contentType) || !POLL_CONTENT_TYPES.has(contentType)) {
        return undefined;
    }

    return meta?.poll;
};

/** What a network's preview draws for a poll: the post's poll and the account's poll limits. */
export interface PreviewPoll {
    poll: Partial<Poll>;
    limits: PollLimits;
}

/** The poll a preview draws, or null for a post that is no poll or an account without poll limits. */
export const previewPollOf = (
    contentType: string | undefined,
    meta: Record<string, any> | undefined,
    publishConfig: PublishConfig | null | undefined,
): PreviewPoll | null => {
    const poll = pollOf(contentType, meta);
    const limits = pollLimitsOf(publishConfig);

    if (!filled(poll) || !filled(limits)) {
        return null;
    }

    return { poll, limits };
};

const ownPollOf = (post: PostCard): Partial<Poll> | undefined =>
    pollOf(post.content_type, post.meta);

/** A post's own polls (the post's and its replies') with no votes yet. */
const unreadPollsOf = (
    post: PostCard,
): { poll: PublicationPoll | null; threadPolls: PublicationThreadPoll[] } => ({
    poll: transform(ownPollOf(post), (poll) =>
        unreadPollResult(poll, post.published_at),
    ),
    threadPolls: threadRepliesOf(post.meta).flatMap((reply, index) => {
        if (filled(reply.poll)) {
            return [
                {
                    reply: index,
                    ...unreadPollResult(reply.poll, post.published_at),
                },
            ];
        }

        return [];
    }),
});

/**
 * The polls a post shows, in every tab: each one as X returned it at the last
 * read, or, until X has been read for it (about an hour after publishing, and
 * never for a post still in the queue, a draft or a request), with its
 * choices and no votes.
 */
export const postPollResults = (
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
