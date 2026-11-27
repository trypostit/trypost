<script setup lang="ts">
/**
 * Label / value rows inside a bordered card, like the settings rows: a muted
 * label on the left, the value on the right. A row may hand its value to a
 * named slot (`#row-<key>`) for a badge or a link.
 */
import type { AdminDetailRow } from '@/types/admin';

defineProps<{
    rows: AdminDetailRow[];
    testId?: string;
}>();
</script>

<template>
    <dl
        class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
        :data-testid="testId"
    >
        <div
            v-for="row in rows"
            :key="row.key"
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-3 sm:px-6"
        >
            <dt class="text-sm text-muted-foreground">{{ row.label }}</dt>
            <dd class="min-w-0 text-sm text-foreground">
                <slot :name="`row-${row.key}`">
                    <span class="break-all">{{ row.value }}</span>
                </slot>
            </dd>
        </div>
    </dl>
</template>
