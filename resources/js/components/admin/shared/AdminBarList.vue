<script setup lang="ts">
import { computed } from 'vue';

import AdminBarRows from '@/components/admin/shared/AdminBarRows.vue';
import AdminEmptyState from '@/components/admin/shared/AdminEmptyState.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { filled } from '@/lib/helpers';
import type { AdminBarRow } from '@/types/admin/shared';

/**
 * A section of counts split into rows, each a bar against the largest, with
 * its count and share; the `#value` slot replaces the count and share columns.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        subtitle?: string;
        rows: AdminBarRow[];
        testId: string;
        /** The label column's width. */
        labelClass?: string;
        /** What the section says when it has no rows. */
        empty?: string;
    }>(),
    { labelClass: 'w-52', empty: 'Nothing in this period.' },
);

const hasRows = computed(() => filled(props.rows));
</script>

<template>
    <AnalyticsSection :title="title" :subtitle="subtitle" flush>
        <AdminEmptyState v-if="!hasRows" variant="section">{{ empty }}</AdminEmptyState>
        <AdminBarRows v-else :rows="rows" :label-class="labelClass" :test-id="testId">
            <template v-if="$slots['value']" #value="{ row }">
                <slot name="value" :row="row" />
            </template>
        </AdminBarRows>
    </AnalyticsSection>
</template>
