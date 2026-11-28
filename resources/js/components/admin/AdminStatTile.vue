<script setup lang="ts">
/**
 * What a backoffice tile shows: a muted label over a large figure, its change
 * and a hint. `AdminStatGrid` and `AdminMetricTabs` lay the tiles out.
 */
import MetricChange from '@/components/analytics/MetricChange.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { AdminChange } from '@/types/admin';

defineProps<{
    label: string;
    value: string;
    hint?: string;
    change?: AdminChange | null;
    /** The figure is still on its way: a pulsing block holds its place. */
    loading?: boolean;
    /** The figure's color, which a metric tab dims while it is not open. */
    valueClass: string;
    valueTestId?: string;
}>();
</script>

<template>
    <span class="contents">
        <span class="flex min-h-6 items-center truncate text-sm text-muted-foreground">{{
            label
        }}</span>
        <span class="flex flex-wrap items-baseline gap-x-2">
            <Skeleton v-if="loading" class="h-8 w-24" :data-testid="valueTestId" aria-busy="true" />
            <span
                v-else
                class="font-heading text-2xl leading-8 font-medium tabular-nums transition-colors"
                :class="valueClass"
                :data-testid="valueTestId"
                >{{ value }}</span
            >
            <MetricChange
                v-if="change"
                :change="change.value"
                :label="change.label"
                :inverted="change.inverted"
                compact
            />
        </span>
        <span v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</span>
    </span>
</template>
