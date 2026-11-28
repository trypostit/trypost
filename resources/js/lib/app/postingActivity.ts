import {
    IconCrownFilled,
    IconFlameFilled,
    IconSendFilled,
    type Icon,
} from '@tabler/icons-vue';

import date from '@/date';
import dayjs from '@/dayjs';
import { filled } from '@/lib/helpers';
import { weekStartIndex } from '@/preferences';
import type { PostingActivityDay } from '@/types/analytics';

/** The three numbers over the grid, in the order the card and the share image show them. */
export type PostingActivityStat = 'current_streak' | 'best_streak' | 'total';

/** Each number's icon and color (a CSS color, so the share card's canvas can resolve it too). */
export const POSTING_ACTIVITY_STAT_ICONS: Record<
    PostingActivityStat,
    { icon: Icon; color: string }
> = {
    current_streak: { icon: IconFlameFilled, color: 'var(--color-orange-500)' },
    best_streak: { icon: IconCrownFilled, color: 'var(--color-amber-400)' },
    total: { icon: IconSendFilled, color: 'var(--chart-3)' },
};

/** The grid's rows: one per weekday, one column per week. */
export const DAYS_PER_WEEK = 7;

/** A month's short name over the column its first day falls in. */
export type PostingActivityMonth = { column: number; label: string };

/** Empty cells before the first day, so it lands on its weekday's row in the user's week. */
export const postingActivityOffset = (days: PostingActivityDay[]): number => {
    const first = days[0];

    if (!filled(first)) {
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
