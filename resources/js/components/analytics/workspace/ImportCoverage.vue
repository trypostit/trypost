<script setup lang="ts">
import { IconClockHour4 } from '@tabler/icons-vue';
import { computed } from 'vue';

import type { CoverageRow } from './types';

const props = defineProps<{ coverage: CoverageRow[] }>();
const pending = computed(() =>
    props.coverage.filter(
        (row) =>
            row.collector === 'publication_backfill' &&
            (row.status === 'pending' || row.status === 'running'),
    ),
);
</script>

<template>
    <div
        v-if="pending.length"
        data-testid="analytics-import-coverage"
        class="flex items-center gap-2.5 rounded-xl border-2 border-foreground bg-violet-50 px-4 py-3 text-sm text-foreground"
        role="status"
    >
        <IconClockHour4
            class="size-4 shrink-0 text-primary"
            aria-hidden="true"
        />
        {{ $t('analytics.dashboard.import_in_progress') }}
    </div>
</template>
