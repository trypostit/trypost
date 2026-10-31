<script setup lang="ts">
import { computed } from 'vue';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import date from '@/date';

type Range = { start: string; end: string };

const props = defineProps<{
    range: Range;
    /** The period the changes are measured against. */
    previous: Range;
}>();

const previousLabel = computed(() =>
    date.formatComparedRange(props.previous, props.range),
);
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
        <span data-testid="analytics-range-current">{{
            date.formatDayMonthYearRange(range)
        }}</span>
        <TooltipProvider :delay-duration="200">
            <Tooltip>
                <TooltipTrigger as-child>
                    <span
                        tabindex="0"
                        class="inline-flex items-center rounded-md bg-muted px-1.5 py-0.5 text-[11px] leading-4 font-medium whitespace-nowrap text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        data-testid="analytics-range-previous"
                    >
                        {{ $t('analytics.ranges.versus', { range: previousLabel }) }}
                    </span>
                </TooltipTrigger>
                <TooltipContent class="max-w-64">
                    {{
                        $t('analytics.ranges.versus_hint', {
                            range: date.formatDayMonthYearRange(previous),
                        })
                    }}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </span>
</template>
