import { computed, type ComputedRef } from 'vue';

import { useUserStorage } from '@/composables/useBrowserStorage';
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

const ordered = (selected: PerformanceMetric[]): PerformanceMetric[] =>
    PERFORMANCE_METRICS.filter((metric) => selected.includes(metric));

/** The Performance table's columns: the ones the user picked, in the table's order, else the defaults. */
export const usePerformanceColumns = (): {
    columns: ComputedRef<PerformanceMetric[]>;
    toggleColumn: (metric: PerformanceMetric) => void;
} => {
    const stored = useUserStorage('insights.performanceColumns', [
        ...DEFAULT_PERFORMANCE_COLUMNS,
    ]);

    const columns = computed((): PerformanceMetric[] => {
        const picked = ordered(stored.value);

        if (picked.length === 0) {
            return [...DEFAULT_PERFORMANCE_COLUMNS];
        }

        return picked;
    });

    const toggleColumn = (metric: PerformanceMetric): void => {
        if (!columns.value.includes(metric)) {
            stored.value = ordered([...columns.value, metric]);

            return;
        }

        if (columns.value.length > 1) {
            stored.value = columns.value.filter((column) => column !== metric);
        }
    };

    return { columns, toggleColumn };
};
