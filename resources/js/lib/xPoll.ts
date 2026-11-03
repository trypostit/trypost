import { activeLocale } from '@/language';
import type { XPoll, XPollDuration, XPollLimits } from '@/types/x';

export const MINUTES_PER_HOUR = 60;
export const HOURS_PER_DAY = 24;
const MINUTES_PER_DAY = HOURS_PER_DAY * MINUTES_PER_HOUR;

/** The poll limits in an account's `publishConfig`, or null on a network without polls. */
export const pollLimitsOf = (
    publishConfig: Record<string, any> | null | undefined,
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

    return Math.min(
        Math.max(total, limits.minDuration),
        limits.maxDuration,
    );
};

export const newPoll = (limits: XPollLimits): XPoll => ({
    options: Array.from({ length: limits.minOptions }, () => ''),
    duration_minutes: limits.defaultDuration,
});

/** The choices the editor shows: the stored ones, padded to the number a poll starts with. */
export const editablePollOptions = (
    options: string[] | undefined,
    minOptions: number,
): string[] => {
    const stored = Array.isArray(options) ? options.map(String) : [];

    return [
        ...stored,
        ...Array.from(
            { length: Math.max(minOptions - stored.length, 0) },
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
