<script setup lang="ts">
import { IconMinus, IconTrendingDown, IconTrendingUp } from '@tabler/icons-vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        change: number;
        label: string;
        compact?: boolean;
        /** Going up is bad news (cancellations, churn): up reads red, down green. */
        inverted?: boolean;
    }>(),
    { compact: false, inverted: false },
);

const goodChange = computed((): number => {
    if (props.inverted) {
        return -props.change;
    }

    return props.change;
});

const tone = computed((): string => {
    if (goodChange.value > 0) {
        return 'text-success-text';
    }

    if (goodChange.value < 0) {
        return 'text-destructive-text';
    }

    return 'text-muted-foreground';
});
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center font-semibold whitespace-nowrap tabular-nums"
        :class="[tone, compact ? 'gap-1 text-xs' : 'gap-1 text-sm']"
    >
        <IconTrendingUp
            v-if="change > 0"
            :class="compact ? 'size-3.5' : 'size-4'"
            class="shrink-0"
            :stroke-width="2.5"
            aria-hidden="true"
        />
        <IconTrendingDown
            v-else-if="change < 0"
            :class="compact ? 'size-3.5' : 'size-4'"
            class="shrink-0"
            :stroke-width="2.5"
            aria-hidden="true"
        />
        <IconMinus
            v-else
            :class="compact ? 'size-3.5' : 'size-4'"
            class="shrink-0"
            :stroke-width="2.5"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
