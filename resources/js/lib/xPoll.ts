import { activeLocale } from '@/language';

export const X_POLL_MIN_OPTIONS = 2;
export const X_POLL_MAX_OPTIONS = 4;
export const X_POLL_OPTION_MAX_LENGTH = 25;
export const X_POLL_MIN_DURATION = 5;
export const X_POLL_MAX_DURATION = 10080;
export const X_POLL_DEFAULT_DURATION = 1440;
/** X caps a poll's question at 280 characters, even for an account with long posts. */
export const X_POLL_QUESTION_MAX_LENGTH = 280;

const MINUTES_PER_HOUR = 60;
const MINUTES_PER_DAY = 1440;
const MAX_DAYS = X_POLL_MAX_DURATION / MINUTES_PER_DAY;

export interface XPollDuration {
    days: number;
    hours: number;
    minutes: number;
}

export interface XPoll {
    options: string[];
    duration_minutes: number;
}

export const splitPollDuration = (minutes: number): XPollDuration => ({
    days: Math.floor(minutes / MINUTES_PER_DAY),
    hours: Math.floor((minutes % MINUTES_PER_DAY) / MINUTES_PER_HOUR),
    minutes: minutes % MINUTES_PER_HOUR,
});

/** Minutes for the picked parts, within X's 5 minutes to 7 days; 7 days takes no hours or minutes. */
export const joinPollDuration = (parts: XPollDuration): number => {
    if (parts.days >= MAX_DAYS) return X_POLL_MAX_DURATION;

    const total =
        parts.days * MINUTES_PER_DAY +
        parts.hours * MINUTES_PER_HOUR +
        parts.minutes;

    return Math.min(
        Math.max(total, X_POLL_MIN_DURATION),
        X_POLL_MAX_DURATION,
    );
};

export const newPoll = (): XPoll => ({
    options: Array.from({ length: X_POLL_MIN_OPTIONS }, () => ''),
    duration_minutes: X_POLL_DEFAULT_DURATION,
});

/** The choices the editor shows: the stored ones, padded to the two a poll starts with. */
export const editablePollOptions = (options: string[] | undefined): string[] => {
    const stored = Array.isArray(options) ? options.map(String) : [];

    return [
        ...stored,
        ...Array.from(
            { length: Math.max(X_POLL_MIN_OPTIONS - stored.length, 0) },
            () => '',
        ),
    ];
};

/** The poll's length in its largest unit, as X shows it ("1 day", "23 hours", "5 minutes"). */
export const formatPollLength = (minutes: number): string => {
    const { days, hours } = splitPollDuration(minutes);
    const [value, unit] =
        days > 0
            ? [days, 'day']
            : hours > 0
              ? [hours, 'hour']
              : [minutes, 'minute'];

    return new Intl.NumberFormat(activeLocale.value, {
        style: 'unit',
        unit,
        unitDisplay: 'long',
    }).format(value);
};
