<script setup lang="ts" generic="Value extends string">
/**
 * A row of figures inside an `AnalyticsSection` (`flush`) where each one
 * picks the chart under it. Drawn like `AdminStatGrid`, with the open one
 * underlined and the others dimmed; each figure is a toggle button.
 */
import AdminStatTile from '@/components/admin/AdminStatTile.vue';
import type { AdminMetricTab } from '@/types/admin';

defineProps<{
    tabs: AdminMetricTab<Value>[];
    testId: string;
}>();

const model = defineModel<Value>({ required: true });

const isOpen = (value: Value): boolean => model.value === value;

const select = (value: Value): void => {
    model.value = value;
};

const figureClass = (value: Value): string => {
    if (isOpen(value)) {
        return 'text-foreground';
    }

    return 'text-muted-foreground group-hover:text-foreground';
};
</script>

<template>
    <div class="grid grid-cols-2 border-t border-border md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            :aria-pressed="isOpen(tab.value)"
            class="group relative flex min-w-0 cursor-pointer flex-col gap-2 px-4 py-4 text-start shadow-[1px_0_0_var(--border),0_1px_0_var(--border)] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5"
            :data-testid="`${testId}-${tab.value}`"
            @click="select(tab.value)"
        >
            <AdminStatTile
                :label="tab.label"
                :value="tab.figure"
                :hint="tab.hint"
                :change="tab.change"
                :value-class="figureClass(tab.value)"
            />
            <span
                v-if="isOpen(tab.value)"
                class="absolute inset-x-0 bottom-0 h-0.5 bg-primary-text"
                aria-hidden="true"
            />
        </button>
    </div>
</template>
