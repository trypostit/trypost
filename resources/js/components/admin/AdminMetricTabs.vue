<script setup lang="ts">
/**
 * A row of figures inside an `AnalyticsSection` (`flush`) where each one is a
 * tab: picking a figure picks the chart under it. Drawn like `AdminStatGrid`,
 * with the open one underlined and the others dimmed.
 */
import type { AdminChange } from '@/components/admin/AdminStatGrid.vue';
import MetricChange from '@/components/analytics/MetricChange.vue';

export interface AdminMetricTab {
    value: string;
    label: string;
    figure: string;
    hint?: string;
    change?: AdminChange | null;
}

const props = defineProps<{
    tabs: AdminMetricTab[];
    testId: string;
}>();

const model = defineModel<string>({ required: true });

const isOpen = (value: string): boolean => model.value === value;

const select = (value: string): void => {
    model.value = value;
};

const figureClass = (value: string): string => {
    if (isOpen(value)) {
        return 'text-foreground';
    }

    return 'text-muted-foreground group-hover:text-foreground';
};

const columns = (): string => `repeat(${props.tabs.length}, minmax(0, 1fr))`;
</script>

<template>
    <div
        class="grid grid-cols-2 border-t border-border md:grid-cols-(--admin-metric-columns)"
        :style="{ '--admin-metric-columns': columns() }"
        role="tablist"
    >
        <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            role="tab"
            :aria-selected="isOpen(tab.value)"
            class="group relative flex min-w-0 cursor-pointer flex-col gap-2 px-4 py-4 text-start shadow-[1px_0_0_var(--border),0_1px_0_var(--border)] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5"
            :data-testid="`${testId}-${tab.value}`"
            @click="select(tab.value)"
        >
            <span class="flex min-h-6 items-center truncate text-sm text-muted-foreground">{{
                tab.label
            }}</span>
            <span class="flex flex-wrap items-baseline gap-x-2">
                <span
                    class="font-heading text-2xl leading-8 font-medium tabular-nums transition-colors"
                    :class="figureClass(tab.value)"
                    >{{ tab.figure }}</span
                >
                <MetricChange
                    v-if="tab.change"
                    :change="tab.change.value"
                    :label="tab.change.label"
                    :inverted="tab.change.inverted"
                    compact
                />
            </span>
            <span v-if="tab.hint" class="text-xs text-muted-foreground">{{ tab.hint }}</span>
            <span
                v-if="isOpen(tab.value)"
                class="absolute inset-x-0 bottom-0 h-0.5 bg-primary-text"
                aria-hidden="true"
            />
        </button>
    </div>
</template>
