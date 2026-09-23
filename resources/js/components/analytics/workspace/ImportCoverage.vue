<script setup lang="ts">
import { computed } from 'vue';

import type { CoverageRow } from './types';

const props = defineProps<{ coverage: CoverageRow[] }>();
const pending = computed(() =>
    props.coverage.filter(
        (row) => row.status !== 'complete' && row.status !== 'completed',
    ),
);
</script>

<template>
    <div
        v-if="pending.length"
        class="rounded-lg border border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground"
        role="status"
    >
        {{ $t('analytics.dashboard.import_in_progress') }}
    </div>
</template>
