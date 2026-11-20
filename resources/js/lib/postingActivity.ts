import date from '@/date';
import dayjs from '@/dayjs';
import { weekStartIndex } from '@/preferences';
import type { PostingActivityDay } from '@/types/analytics';

/** The grid's rows: one per weekday, one column per week. */
export const DAYS_PER_WEEK = 7;

/** A month's short name over the column its first day falls in. */
export type PostingActivityMonth = { column: number; label: string };

/** Empty cells before the first day, so it lands on its weekday's row in the user's week. */
export const postingActivityOffset = (days: PostingActivityDay[]): number => {
    const first = days[0];

    if (!first) {
        return 0;
    }

    const weekday = dayjs(first.date).day();

    return (weekday - weekStartIndex() + DAYS_PER_WEEK) % DAYS_PER_WEEK;
};

/** The column each month starts in, for the labels over the grid; the page and the share card read the same ones. */
export const postingActivityMonths = (
    days: PostingActivityDay[],
    offset: number,
): PostingActivityMonth[] =>
    days.flatMap((day, index) => {
        const when = dayjs(day.date);

        if (when.date() !== 1) {
            return [];
        }

        return [
            {
                column: Math.floor((index + offset) / DAYS_PER_WEEK),
                label: date.formatShortMonth(when.month() + 1),
            },
        ];
    });
