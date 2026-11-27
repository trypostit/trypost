<script setup lang="ts">
import { computed } from 'vue';

import { churnSignal } from '@/lib/churnRisk';

/** One churn signal as a neutral outlined chip with its icon. */
const props = defineProps<{
    signal: string;
}>();

const known = computed(() => churnSignal(props.signal));
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-xs whitespace-nowrap text-muted-foreground"
        :data-testid="`churn-signal-${signal}`"
    >
        <component :is="known.icon" class="size-3.5 shrink-0" aria-hidden="true" />
        {{ known.label }}
    </span>
</template>
