<script setup lang="ts">
import { IconMinus, IconTrendingDown, IconTrendingUp } from '@tabler/icons-vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        change: number;
        label: string;
        compact?: boolean;
    }>(),
    { compact: false },
);

const tone = computed((): string => {
    if (props.change > 0) {
        return 'text-success-text';
    }

    if (props.change < 0) {
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
