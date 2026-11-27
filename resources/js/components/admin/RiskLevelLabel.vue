<script setup lang="ts">
import { computed } from 'vue';

import { riskLevel } from '@/lib/churnRisk';
import type { AdminRiskLevel } from '@/types/admin';

/** A churn risk level: a colored dot and its name. */
const props = defineProps<{
    level: AdminRiskLevel;
}>();

const shown = computed(() => riskLevel(props.level));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 text-sm text-foreground">
        <span class="size-2 rounded-full" :class="shown.dot" aria-hidden="true" />
        {{ shown.label }}
    </span>
</template>
