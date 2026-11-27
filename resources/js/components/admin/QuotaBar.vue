<script setup lang="ts">
import { computed } from 'vue';

import { clamp } from '@/lib/helpers';
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
    if (props.limit === null) {
        return 0;
    }

    return clamp(percentOf(props.used, Math.max(props.limit, 0)), 0, 100);
});

const hasLimit = computed((): boolean => {
    if (props.limit === null) {
        return false;
    }

    return props.limit > 0;
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
    if (props.limit === null) {
        return formatFullNumber(props.used);
    }

    return `${formatFullNumber(props.used)} of ${formatFullNumber(props.limit)}`;
});
</script>

<template>
    <div class="flex flex-col gap-2" :data-testid="testId">
        <div class="flex items-baseline justify-between gap-3 text-sm">
            <span class="min-w-0 truncate text-foreground">{{ label }}</span>
            <span class="shrink-0 text-muted-foreground tabular-nums">
                {{ usedLabel }}
                <span v-if="hasLimit" class="ms-1 text-foreground">{{
                    formatPercent(share, 1)
                }}</span>
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
