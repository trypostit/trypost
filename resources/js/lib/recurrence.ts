import type { Dayjs } from 'dayjs';

import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

export type RecurrenceFrequency = 'day' | 'week' | 'month' | 'year';

export const RECURRENCE_FREQUENCIES: readonly RecurrenceFrequency[] = [
    'day',
    'week',
    'month',
    'year',
];

export const MAX_RECURRENCE_INTERVAL = 365;

export const MAX_RECURRENCE_TIMES = 100;

export interface RecurrenceRule {
    interval: number;
    frequency: RecurrenceFrequency;
    times: number;
}

export interface RecurrenceSummary {
    ruleKey: string;
    interval: number;
    params: Record<string, string>;
    until: string;
}

interface RecurringPost {
    scheduled_at: string | null;
    recurrence_interval?: number | null;
    recurrence_frequency?: RecurrenceFrequency | null;
    recurrence_remaining?: number | null;
    recurrence_origin_at?: string | null;
}

export const isRecurring = (post: RecurringPost): boolean =>
    !!post.recurrence_frequency && !!post.recurrence_interval;

export const recurrenceRuleOf = (post: RecurringPost): RecurrenceRule | null =>
    isRecurring(post)
        ? {
              interval: post.recurrence_interval as number,
              frequency: post.recurrence_frequency as RecurrenceFrequency,
              times: post.recurrence_remaining ?? 1,
          }
        : null;

const WALL_CLOCK = 'YYYY-MM-DDTHH:mm:ss';

const wallClock = (instant: string, timezone: string): Dayjs =>
    dayjs.utc(dayjs.utc(instant).tz(timezone).format(WALL_CLOCK));

/**
 * Mirrors ScheduleNextOccurrence: every occurrence is counted from the series
 * origin, and a post moved off its series starts a new one at its own time.
 */
const seriesPosition = (
    scheduledAt: string,
    originAt: string | null,
    timezone: string,
    rule: RecurrenceRule,
): { origin: string; index: number } => {
    const current = wallClock(scheduledAt, timezone);

    if (originAt) {
        const origin = wallClock(originAt, timezone);
        let index = 0;
        let occurrence = origin;

        while (occurrence.isBefore(current)) {
            index++;
            occurrence = origin.add(rule.interval * index, rule.frequency);
        }

        if (occurrence.isSame(current)) {
            return { origin: originAt, index };
        }
    }

    return { origin: scheduledAt, index: 0 };
};

export const summarizeRecurrence = (
    scheduledAt: string,
    timezone: string,
    rule: RecurrenceRule,
    originAt: string | null = null,
): RecurrenceSummary => {
    const position = seriesPosition(scheduledAt, originAt, timezone, rule);
    const start = dayjs
        .utc(position.origin)
        .tz(timezone)
        .locale(activeLocale.value.toLowerCase());
    const last = wallClock(position.origin, timezone).add(
        rule.interval * (position.index + rule.times),
        rule.frequency,
    );

    return {
        ruleKey: `posts.recurrence.rule.${rule.frequency}`,
        interval: rule.interval,
        params: {
            count: String(rule.interval),
            time: start.format(date.timeToken()),
            weekday: start.format('dddd'),
            day: start.format('Do'),
            date: new Intl.DateTimeFormat(activeLocale.value, {
                month: 'long',
                day: 'numeric',
                timeZone: timezone,
            }).format(start.toDate()),
        },
        until: new Intl.DateTimeFormat(activeLocale.value, {
            dateStyle: 'medium',
            timeZone: 'UTC',
        }).format(last.toDate()),
    };
};
