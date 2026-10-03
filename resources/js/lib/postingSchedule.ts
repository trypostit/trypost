import type { PostingSchedule } from '@/types/posting-schedule';

export const MAX_TIMES_PER_DAY = 4;
export const MAX_GOAL = 28;

export type DayTarget = 'every_day' | 'weekdays' | 'weekends' | number;

export const emptySchedule = (): PostingSchedule =>
    [0, 1, 2, 3, 4, 5, 6].map((day) => ({ day, enabled: true, times: [] }));

const replaceDay = (
    schedule: PostingSchedule,
    day: number,
    changes: Partial<{ enabled: boolean; times: string[] }>,
): PostingSchedule => schedule.map((entry) => (entry.day === day ? { ...entry, ...changes } : entry));

export const withTime = (schedule: PostingSchedule, day: number, time: string): PostingSchedule => {
    const times = schedule[day].times;

    if (times.includes(time)) {
        return schedule;
    }

    if (times.length >= MAX_TIMES_PER_DAY) {
        throw new Error('limit');
    }

    return replaceDay(schedule, day, { times: [...times, time].sort() });
};

export const withoutTime = (schedule: PostingSchedule, day: number, time: string): PostingSchedule =>
    replaceDay(schedule, day, { times: schedule[day].times.filter((t) => t !== time) });

export const withDayEnabled = (schedule: PostingSchedule, day: number, enabled: boolean): PostingSchedule =>
    replaceDay(schedule, day, { enabled });

export const cleared = (schedule: PostingSchedule): PostingSchedule =>
    schedule.map((entry) => ({ ...entry, times: [] }));

export const slotCount = (schedule: PostingSchedule): number =>
    schedule.reduce((total, entry) => total + (entry.enabled ? entry.times.length : 0), 0);

export const targetDays = (target: DayTarget): number[] => {
    if (target === 'every_day') return [0, 1, 2, 3, 4, 5, 6];
    if (target === 'weekdays') return [1, 2, 3, 4, 5];
    if (target === 'weekends') return [0, 6];

    return [target];
};

export const canAddTo = (schedule: PostingSchedule, days: number[]): boolean =>
    days.every((day) => schedule[day].times.length < MAX_TIMES_PER_DAY);

export const hourWindow = (time: string): { start: string; end: string } => {
    const hour = Number(time.slice(0, 2));

    return {
        start: `${String(hour).padStart(2, '0')}:00`,
        end: `${String((hour + 1) % 24).padStart(2, '0')}:00`,
    };
};
