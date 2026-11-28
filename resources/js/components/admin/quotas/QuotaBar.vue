<script setup lang="ts">
import { computed } from 'vue';

import { clamp, isNumber } from '@/lib/helpers';
import { formatFullNumber, formatPercent, percentOf } from '@/lib/utils';

/** From this share of a quota the bar turns amber, then red. */
const WARNING_SHARE = 70;
const DANGER_SHARE = 90;

const props = defineProps<{
    label: string;
    used: number;
    limit: number | null;
    testId?: string;
}>();

const share = computed((): number => {
    if (isNumber(props.limit)) {
        return clamp(percentOf(props.used, Math.max(props.limit, 0)), 0, 100);
    }

    return 0;
});

const hasLimit = computed((): boolean => {
    if (isNumber(props.limit)) {
        return props.limit > 0;
    }

    return false;
});

const tone = computed((): string => {
    if (share.value >= DANGER_SHARE) {
        return 'bg-destructive';
    }

    if (share.value >= WARNING_SHARE) {
        return 'bg-warning';
    }

    return 'bg-primary-strong';
});

const barStyle = computed(() => ({ width: `${share.value}%` }));

const usedLabel = computed((): string => {
    if (isNumber(props.limit)) {
        return `${formatFullNumber(props.used)} of ${formatFullNumber(props.limit)}`;
    }

    return formatFullNumber(props.used);
});

const shareLabel = computed((): string => formatPercent(share.value, 1));
</script>

<template>
    <div class="flex flex-col gap-2" :data-testid="testId">
        <div class="flex items-baseline justify-between gap-3 text-sm">
            <span class="min-w-0 truncate text-foreground">{{ label }}</span>
            <span class="shrink-0 text-muted-foreground tabular-nums">
                {{ usedLabel }}
                <span v-if="hasLimit" class="ms-1 text-foreground">{{ shareLabel }}</span>
            </span>
        </div>
        <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full transition-all"
                :class="tone"
                :style="barStyle"
            />
        </div>
    </div>
</template>
