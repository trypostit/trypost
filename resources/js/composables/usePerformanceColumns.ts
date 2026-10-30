import { computed, ref, type ComputedRef, type Ref } from 'vue';

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

export type PerformanceSortKey = PerformanceMetric | 'channel';
export type PerformanceSortDirection = 'asc' | 'desc';

const columns = ref<PerformanceMetric[]>(readColumns());
const sortBy = ref<PerformanceSortKey>('posts');
const sortDirection = ref<PerformanceSortDirection>('desc');

/** The sorted column, or the first shown one once the sorted column is hidden. */
const sortKey = computed<PerformanceSortKey>(() =>
    sortBy.value === 'channel' || columns.value.includes(sortBy.value)
        ? sortBy.value
        : columns.value[0],
);

export const usePerformanceColumns = (): {
    columns: Ref<PerformanceMetric[]>;
    toggleColumn: (metric: PerformanceMetric) => void;
    sortBy: Ref<PerformanceSortKey>;
    sortDirection: Ref<PerformanceSortDirection>;
    sortKey: ComputedRef<PerformanceSortKey>;
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

    return { columns, toggleColumn, sortBy, sortDirection, sortKey };
};
