import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import { clamp, filled, transform } from '@/lib/helpers';
import { threadRepliesOf } from '@/lib/threadReplies';
import type {
    PublicationPoll,
    PublicationThreadPoll,
} from '@/types/analytics';
import { type ContentTypeValue, POLL_CONTENT_TYPES } from '@/types/content-type';
import type { PublishConfig } from '@/types/platform';
import type { Poll, PollDuration, PollLimits } from '@/types/poll';
import type { PostCard } from '@/types/publish';

export const MINUTES_PER_HOUR = 60;
export const HOURS_PER_DAY = 24;
const MINUTES_PER_DAY = HOURS_PER_DAY * MINUTES_PER_HOUR;

/** The poll limits in an account's `publishConfig`, or null on a network without polls. */
export const pollLimitsOf = (
    publishConfig: PublishConfig | null | undefined,
): PollLimits | null => {
    const limits = publishConfig?.poll;

    if (!filled(limits)) {
        return null;
    }

    return limits;
};

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

    if (!filled(limits.defaultDuration)) {
        return { options };
    }

    return { options, duration_minutes: limits.defaultDuration };
};

/**
 * A choice's length without the spaces around it, in UTF-16 units (a string's
 * `length`), so an emoji like 🐧 counts two: X counts that way, and the server
 * counts every network's choices the same (`Poll::choiceLength()`).
 */
export const pollChoiceLength = (choice: string): number => choice.trim().length;

/**
 * A choice cut to the longest the network accepts, counted by
 * `pollChoiceLength`: the last character goes until it fits, so a character
 * is never split and the spaces before the choice stay.
 */
export const fitPollChoice = (choice: string, maxLength: number): string => {
    let characters = Array.from(choice);

    while (pollChoiceLength(characters.join('')) > maxLength) {
        characters = characters.slice(0, -1);
    }

    return characters.join('');
};

/** The stored choices as text, none when the poll has no list yet. */
const storedPollOptions = (options: string[] | undefined): string[] => {
    if (!Array.isArray(options)) {
        return [];
    }

    return options.map(String);
};

/** The choices the editor shows: the stored ones, padded to the number a poll starts with. */
export const editablePollOptions = (
    options: string[] | undefined,
    minOptions: number,
): string[] => {
    const stored = storedPollOptions(options);

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

/** The largest unit the length fills at least once; minutes below a minute. */
const pollLengthUnit = (
    minutes: number,
): (typeof POLL_LENGTH_UNITS)[number] => {
    const unit = POLL_LENGTH_UNITS.find(([, size]) => minutes >= size);

    if (!filled(unit)) {
        return POLL_LENGTH_UNITS[POLL_LENGTH_UNITS.length - 1];
    }

    return unit;
};

/** The poll's length in its largest whole unit ("1 day", "23 hours", "5 minutes"). */
export const formatPollLength = (minutes: number): string => {
    const [unit, size] = pollLengthUnit(minutes);

    return new Intl.NumberFormat(activeLocale.value, {
        style: 'unit',
        unit,
        unitDisplay: 'long',
    }).format(Math.floor(minutes / size));
};

/**
 * When a published poll ends: its length from publishing. Unknown before it
 * is published, and on a network that sets the length itself (Threads).
 */
const pollEndsAt = (
    poll: Partial<Poll>,
    publishedAt: string | null,
): string | null => {
    if (!filled(publishedAt) || !filled(poll.duration_minutes)) {
        return null;
    }

    return dayjs.utc(publishedAt).add(poll.duration_minutes, 'minute').toISOString();
};

/**
 * A poll before its network has been read for it: its choices with no votes.
 * Once its post is published it is open; before that, voting has not started.
 * Votes hidden until the end (Mastodon) show as hidden from the start.
 */
const unreadPollResult = (
    poll: Partial<Poll>,
    publishedAt: string | null,
): PublicationPoll => ({
    options: storedPollOptions(poll.options).map((label) => ({ label, votes: 0 })),
    voting_status: transform(publishedAt, (): 'open' => 'open'),
    end_datetime: pollEndsAt(poll, publishedAt),
    duration_minutes: poll.duration_minutes ?? null,
    totals_hidden: poll.hide_totals === true,
});

/** A post's own poll: only a poll type has one, whatever its meta still holds. */
export const pollOf = (
    contentType: ContentTypeValue | null | undefined,
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
    contentType: ContentTypeValue | undefined,
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

/** A post's own polls (the post's and its replies') with no votes yet. */
const unreadPollsOf = (
    post: PostCard,
): { poll: PublicationPoll | null; threadPolls: PublicationThreadPoll[] } => ({
    poll: transform(pollOf(post.content_type, post.meta), (poll) =>
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

/** The polls the network returned at the last read, none before the first one. */
const readPollsOf = (
    post: PostCard,
): { poll: PublicationPoll | null; threadPolls: PublicationThreadPoll[] } => {
    const metrics = post.metrics;

    if (!filled(metrics) || !metrics.available) {
        return { poll: null, threadPolls: [] };
    }

    return {
        poll: metrics.publication.poll,
        threadPolls: metrics.publication.thread_polls,
    };
};

/**
 * The polls a post shows, in every tab: each one as its network returned it
 * at the last read, or, until it has been read (about an hour after
 * publishing, and never for a post still in the queue, a draft or a request),
 * with its choices and no votes.
 */
export const postPollResults = (
    post: PostCard,
): { poll: PublicationPoll | null; threadPolls: PublicationThreadPoll[] } => {
    const read = readPollsOf(post);
    const unread = unreadPollsOf(post);
    const unreadReplies = unread.threadPolls.filter(
        (unreadReply) =>
            !read.threadPolls.some((readReply) => readReply.reply === unreadReply.reply),
    );

    const threadPolls = [...read.threadPolls, ...unreadReplies].sort(
        (first, second) => first.reply - second.reply,
    );

    if (filled(read.poll)) {
        return { poll: read.poll, threadPolls };
    }

    return { poll: unread.poll, threadPolls };
};
