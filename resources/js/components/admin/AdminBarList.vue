<script setup lang="ts">
import { computed } from 'vue';

import AdminBarRows from '@/components/admin/AdminBarRows.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import { filled } from '@/lib/helpers';
import type { AdminBarRow } from '@/types/admin';

/** Counts split into rows, each a bar against the largest, with its count and share. */
const props = defineProps<{
    title: string;
    subtitle?: string;
    rows: AdminBarRow[];
    testId: string;
}>();

const hasRows = computed(() => filled(props.rows));
</script>

<template>
    <AnalyticsSection :title="title" :subtitle="subtitle" flush>
        <p
            v-if="!hasRows"
            class="border-t border-border px-4 py-6 text-center text-sm text-muted-foreground sm:px-5"
        >
            Nothing in this period.
        </p>
        <AdminBarRows v-else :rows="rows" :test-id="testId" />
    </AnalyticsSection>
</template>
