<script setup lang="ts">
import { computed } from 'vue';

import { labelTextColor } from '@/lib/labelColor';
import type { PostCardLabel } from '@/types/publish';

const props = defineProps<{
    label: PostCardLabel;
}>();

/** The label's own color as the background, with the text color that reads on it. */
const colors = computed(() => ({
    backgroundColor: props.label.color,
    color: labelTextColor(props.label.color),
}));
</script>

<template>
    <span
        class="inline-flex h-6 max-w-full min-w-0 items-center rounded-full px-2.5 text-xs font-medium text-foreground"
        :style="colors"
    >
        <span class="truncate">{{ label.name }}</span>
    </span>
</template>
