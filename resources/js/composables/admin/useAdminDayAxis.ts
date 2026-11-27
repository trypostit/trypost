import { computed, type ComputedRef } from 'vue';

import { numericTick } from '@/components/ui/chart';
import date from '@/date';
import { filled, isNumber } from '@/lib/helpers';

/** One label every few days, so a long range stays readable. */
const MAX_TICKS = 8;

/** The room the backoffice's daily charts leave around their plot. */
export const DAILY_CHART_PADDING = { top: 14, right: 8, bottom: 8, left: 0 };

/** A day's value from a list in the order of the days; a day past its end reads 0. */
export const valueAt = (values: number[], index: number): number => {
    const value = values[index];

    if (isNumber(value)) {
        return value;
    }

    return 0;
};

/**
 * The day axis the backoffice's daily charts share: a label per day
 * ("Nov 3"), the days that get a tick, and the label a tick or tooltip reads.
 */
export const useAdminDayAxis = (
    days: () => string[],
): {
    labels: ComputedRef<string[]>;
    ticks: ComputedRef<number[]>;
    formatDay: ReturnType<typeof numericTick<string>>;
} => {
    const labels = computed((): string[] => days().map((day) => date.formatMonthDay(day)));

    const ticks = computed((): number[] => {
        const step = Math.max(1, Math.ceil(days().length / MAX_TICKS));

        return days()
            .map((_, index) => index)
            .filter((index) => index % step === 0);
    });

    const formatDay = numericTick((tick): string => {
        const label = labels.value[Math.round(tick)];

        if (filled(label)) {
            return label;
        }

        return '';
    });

    return { labels, ticks, formatDay };
};
