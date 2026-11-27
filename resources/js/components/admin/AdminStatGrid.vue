<script setup lang="ts">
/**
 * A row of numbers inside an `AnalyticsSection` (`flush`), drawn like the
 * Insights summary cards: a muted label over a large figure.
 */
import MetricChange from '@/components/analytics/MetricChange.vue';

export interface AdminChange {
    /** The change in percent; its sign picks the arrow. */
    value: number;
    label: string;
    inverted?: boolean;
}

export interface AdminStat {
    key: string;
    label: string;
    value: string;
    hint?: string;
    change?: AdminChange | null;
}

defineProps<{
    stats: AdminStat[];
}>();
</script>

<template>
    <div
        class="grid grid-cols-2 border-t border-border md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]"
    >
        <div
            v-for="stat in stats"
            :key="stat.key"
            class="flex min-w-0 flex-col gap-2 px-4 py-4 shadow-[1px_0_0_var(--border),0_1px_0_var(--border)] sm:px-5"
            :data-testid="`admin-stat-${stat.key}`"
        >
            <p class="flex min-h-6 items-center truncate text-sm text-muted-foreground">
                {{ stat.label }}
            </p>
            <span class="flex flex-wrap items-baseline gap-x-2">
                <span
                    class="font-heading text-2xl leading-8 font-medium text-foreground tabular-nums"
                    :data-testid="`admin-stat-${stat.key}-value`"
                    >{{ stat.value }}</span
                >
                <MetricChange
                    v-if="stat.change"
                    :change="stat.change.value"
                    :label="stat.change.label"
                    :inverted="stat.change.inverted"
                    compact
                />
            </span>
            <p v-if="stat.hint" class="text-xs text-muted-foreground">
                {{ stat.hint }}
            </p>
        </div>
    </div>
</template>
