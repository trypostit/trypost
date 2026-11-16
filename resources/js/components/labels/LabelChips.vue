<script setup lang="ts">
import { computed } from 'vue';

import LabelBadge from '@/components/labels/LabelBadge.vue';
import type { PostCardLabel } from '@/types/publish';

const props = withDefaults(
    defineProps<{
        labels: PostCardLabel[];
        /** How many chips show before the rest is counted as "+N". */
        max?: number;
        /** Prefix of each chip's test id (`<testId>-<label id>`) and of the count (`<testId>-more`). */
        testId: string;
    }>(),
    { max: 2 },
);

const shown = computed(() => props.labels.slice(0, props.max));

const hiddenCount = computed(() => props.labels.length - shown.value.length);

const hasHidden = computed(() => hiddenCount.value > 0);
</script>

<template>
    <span class="flex min-w-0 items-center gap-1.5 overflow-hidden">
        <LabelBadge
            v-for="label in shown"
            :key="label.id"
            :label="label"
            :data-testid="`${testId}-${label.id}`"
        />
        <span
            v-if="hasHidden"
            class="shrink-0 text-muted-foreground"
            :data-testid="`${testId}-more`"
            >+{{ hiddenCount }}</span
        >
    </span>
</template>
