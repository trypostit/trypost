<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, type Component } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { filled, isNumber } from '@/lib/helpers';
import { formatFullNumber, formatPercent } from '@/lib/utils';
import type { AdminBarRow } from '@/types/admin/shared';

/**
 * Counts as rows, each a bar against the largest (or its own `bar` length),
 * with its count and share. A row with `href` links there; the `#value` slot
 * replaces the count and share columns.
 */
const props = withDefaults(
    defineProps<{
        rows: AdminBarRow[];
        testId: string;
        /** The label column's width. */
        labelClass?: string;
    }>(),
    { labelClass: 'w-52' },
);

const most = computed((): number => Math.max(1, ...props.rows.map((row) => row.count)));

const barStyle = (row: AdminBarRow): { width: string } => {
    if (isNumber(row.bar)) {
        return { width: `${row.bar}%` };
    }

    return { width: `${(row.count / most.value) * 100}%` };
};

const barClass = (row: AdminBarRow): string => {
    if (filled(row.barClass)) {
        return row.barClass;
    }

    return 'bg-primary-text';
};

const hasShare = (row: AdminBarRow): boolean => isNumber(row.share);

const rowElement = (row: AdminBarRow): Component | string => {
    if (filled(row.href)) {
        return Link;
    }

    return 'div';
};

const rowClass = (row: AdminBarRow): string => {
    if (filled(row.href)) {
        return 'flex items-center gap-3 px-4 py-1.5 transition-colors hover:bg-muted/60 sm:px-5';
    }

    return 'flex items-center gap-3 px-4 py-1.5 sm:px-5';
};
</script>

<template>
    <ul class="flex flex-col border-t border-border py-2" :data-testid="testId">
        <li v-for="row in rows" :key="row.key">
            <component :is="rowElement(row)" :href="row.href" :class="rowClass(row)">
                <span class="flex shrink-0 items-center gap-2.5" :class="labelClass">
                    <PlatformLogo v-if="row.platform" :platform="row.platform" size="xs" />
                    <span class="truncate text-sm text-foreground">{{ row.label }}</span>
                </span>
                <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                    <span class="block h-full rounded-full" :class="barClass(row)" :style="barStyle(row)" />
                </span>
                <span v-if="row.hint" class="w-28 shrink-0 text-right text-xs text-muted-foreground tabular-nums">{{
                    row.hint
                }}</span>
                <slot name="value" :row="row">
                    <span class="w-14 shrink-0 text-right text-sm font-medium text-foreground tabular-nums">{{
                        formatFullNumber(row.count)
                    }}</span>
                    <span
                        v-if="hasShare(row)"
                        class="w-14 shrink-0 text-right text-sm text-muted-foreground tabular-nums"
                        >{{ formatPercent(row.share, 1) }}</span
                    >
                </slot>
            </component>
        </li>
    </ul>
</template>
