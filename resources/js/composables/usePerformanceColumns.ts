import { ref, type Ref } from 'vue';

import type { PerformanceMetric } from '@/types/analytics';

export type { PerformanceMetric };

export const PERFORMANCE_METRICS: readonly PerformanceMetric[] = [
    'posts',
    'reactions',
    'comments',
    'engagement_rate',
    'reposts',
    'impressions',
    'clicks',
    'views',
    'shares',
    'saves',
    'follows_gained',
    'reach',
    'watch_time_minutes',
    'average_watch_time_seconds',
];

export const DEFAULT_PERFORMANCE_COLUMNS: readonly PerformanceMetric[] = [
    'posts',
    'reactions',
    'comments',
    'engagement_rate',
];

const STORAGE_KEY = 'insights.performanceColumns';

const ordered = (selected: PerformanceMetric[]): PerformanceMetric[] =>
    PERFORMANCE_METRICS.filter((metric) => selected.includes(metric));

const readColumns = (): PerformanceMetric[] => {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? 'null',
        );

        if (Array.isArray(stored)) {
            const columns = ordered(stored as PerformanceMetric[]);

            if (columns.length > 0) {
                return columns;
            }
        }
    } catch {
        return [...DEFAULT_PERFORMANCE_COLUMNS];
    }

    return [...DEFAULT_PERFORMANCE_COLUMNS];
};

const columns = ref<PerformanceMetric[]>(readColumns());

export const usePerformanceColumns = (): {
    columns: Ref<PerformanceMetric[]>;
    toggleColumn: (metric: PerformanceMetric) => void;
} => {
    const toggleColumn = (metric: PerformanceMetric): void => {
        const next = columns.value.includes(metric)
            ? columns.value.filter((column) => column !== metric)
            : ordered([...columns.value, metric]);

        if (next.length === 0) {
            return;
        }

        columns.value = next;

        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        } catch {
            return;
        }
    };

    return { columns, toggleColumn };
};
