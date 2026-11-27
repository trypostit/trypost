<script setup lang="ts" generic="Row extends Record<string, unknown>">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

/**
 * A full-width table drawn like the Insights performance table: a muted
 * header whose columns sort on click (numbers high first, text A to Z), and
 * a slot per column (`#cell-<key>`) for how a value shows.
 */
export interface AdminTableColumn {
    key: string;
    label: string;
    numeric?: boolean;
}

type SortDirection = 'asc' | 'desc';

const props = defineProps<{
    columns: AdminTableColumn[];
    rows: Row[];
    rowKey: (row: Row) => string;
    defaultSort: string;
    testId?: string;
}>();

const sortKey = ref<string>(props.defaultSort);
const sortDirection = ref<SortDirection>('desc');

const isNumeric = (key: string): boolean =>
    props.columns.find((column) => column.key === key)?.numeric === true;

const compare = (left: Row, right: Row): number => {
    const a = left[sortKey.value];
    const b = right[sortKey.value];

    if (isNumeric(sortKey.value)) {
        return Number(a ?? 0) - Number(b ?? 0);
    }

    return String(a ?? '').localeCompare(String(b ?? ''));
};

const sorted = computed((): Row[] => {
    const rows = [...props.rows].sort(compare);

    if (sortDirection.value === 'desc') {
        return rows.reverse();
    }

    return rows;
});

const sortOn = (key: string): void => {
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';

        return;
    }

    sortKey.value = key;
    sortDirection.value = isNumeric(key) ? 'desc' : 'asc';
};

const isSorted = (key: string): boolean => sortKey.value === key;

const ariaSort = (key: string): 'ascending' | 'descending' | 'none' => {
    if (!isSorted(key)) {
        return 'none';
    }

    if (sortDirection.value === 'asc') {
        return 'ascending';
    }

    return 'descending';
};

const sortIcon = (key: string) => {
    if (isSorted(key) && sortDirection.value === 'asc') {
        return IconChevronUp;
    }

    return IconChevronDown;
};

const sortIconClass = (key: string): string => {
    if (isSorted(key)) {
        return 'size-3 opacity-100 transition-opacity';
    }

    return 'size-3 opacity-30 transition-opacity group-hover:opacity-60';
};

const headClass = (column: AdminTableColumn, index: number): string => {
    const base = 'h-10 border-r-0 px-4 py-2 text-xs font-medium whitespace-nowrap text-muted-foreground';

    if (index === 0) {
        return `${base} sm:ps-5`;
    }

    return base;
};

const cellClass = (column: AdminTableColumn, index: number): string => {
    if (index === 0) {
        return 'border-r-0 px-4 py-3 sm:ps-5';
    }

    if (column.numeric) {
        return 'border-r-0 px-4 py-3 whitespace-nowrap tabular-nums';
    }

    return 'border-r-0 px-4 py-3';
};
</script>

<template>
    <div class="min-w-0 border-t border-border">
        <div class="min-w-0 overflow-x-auto">
            <Table class="min-w-[720px]" :data-testid="testId">
                <TableHeader>
                    <TableRow class="border-border bg-muted/60 hover:bg-muted/60">
                        <TableHead
                            v-for="(column, index) in columns"
                            :key="column.key"
                            :class="headClass(column, index)"
                            :aria-sort="ariaSort(column.key)"
                        >
                            <button
                                type="button"
                                class="group inline-flex cursor-pointer items-center gap-1 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                :data-testid="`${testId}-sort-${column.key}`"
                                @click="sortOn(column.key)"
                            >
                                {{ column.label }}
                                <component
                                    :is="sortIcon(column.key)"
                                    :class="sortIconClass(column.key)"
                                    aria-hidden="true"
                                />
                            </button>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="row in sorted"
                        :key="rowKey(row)"
                        class="border-border last:border-b-0"
                    >
                        <TableCell
                            v-for="(column, index) in columns"
                            :key="column.key"
                            :class="cellClass(column, index)"
                        >
                            <slot :name="`cell-${column.key}`" :row="row">
                                <span class="text-sm font-medium text-foreground">{{
                                    row[column.key]
                                }}</span>
                            </slot>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
