<script setup lang="ts">
import PlatformLogo from '@/components/PlatformLogo.vue';
import { formatFullNumber, formatPercent } from '@/lib/utils';
import type { AdminBarRow } from '@/types/admin';

/** Counts as rows, each a bar against the largest, with its count and share. */
const props = defineProps<{
    rows: AdminBarRow[];
    testId: string;
}>();

const most = (): number => Math.max(1, ...props.rows.map((row) => row.count));

const barWidth = (count: number): string => `${(count / most()) * 100}%`;
</script>

<template>
    <ul class="flex flex-col border-t border-border py-2" :data-testid="testId">
        <li v-for="row in rows" :key="row.key" class="flex items-center gap-3 px-4 py-1.5 sm:px-5">
            <span class="flex w-52 shrink-0 items-center gap-2.5">
                <PlatformLogo v-if="row.platform" :platform="row.platform" size="xs" />
                <span class="truncate text-sm text-foreground">{{ row.label }}</span>
            </span>
            <span class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                <span class="block h-full rounded-full bg-primary-text" :style="{ width: barWidth(row.count) }" />
            </span>
            <span v-if="row.hint" class="w-28 shrink-0 text-right text-xs text-muted-foreground tabular-nums">{{
                row.hint
            }}</span>
            <span class="w-14 shrink-0 text-right text-sm font-medium text-foreground tabular-nums">{{
                formatFullNumber(row.count)
            }}</span>
            <span class="w-14 shrink-0 text-right text-sm text-muted-foreground tabular-nums">{{
                formatPercent(row.share, 1)
            }}</span>
        </li>
    </ul>
</template>
