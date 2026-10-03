<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

import { DateRangePicker } from '@/components/ui/date-range-picker';
import date from '@/date';
import dayjs from '@/dayjs';
import type {
    AnalyticsFilters,
    AnalyticsRangePreset,
} from '@/types/analytics';

const props = defineProps<{
    filters: AnalyticsFilters;
    bounds: { min: string | null; max: string | null };
    url: string;
    range: { start: string; end: string };
    previousRange: { start: string; end: string };
    keep?: Record<string, string | string[]>;
    hideCaption?: boolean;
}>();

const presets: Exclude<AnalyticsRangePreset, 'custom'>[] = [
    '7d',
    '30d',
    'mtd',
    'last_month',
];
const buttonClass = (preset: AnalyticsRangePreset): string =>
    props.filters.range === preset
        ? 'bg-primary-selected text-primary-text'
        : 'text-muted-foreground hover:bg-accent hover:text-foreground';
const toDates = (start: string, end: string): { start: Date; end: Date } => ({
    start: dayjs(start).toDate(),
    end: dayjs(end).toDate(),
});
const selectedRange = ref(toDates(props.range.start, props.range.end));

watch(
    () => [props.range.start, props.range.end],
    ([start, end]) => {
        selectedRange.value = toDates(start, end);
    },
);

const span = (range: { start: string; end: string }): string =>
    `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`;

const choose = (preset: AnalyticsRangePreset): void => {
    router.get(props.url, { ...props.keep, range: preset });
};

const changeRange = (range: { start: Date; end: Date }): void => {
    selectedRange.value = range;
    const start = dayjs(range.start).format('YYYY-MM-DD');
    const end = dayjs(range.end).format('YYYY-MM-DD');

    if (start === props.range.start && end === props.range.end) {
        return;
    }

    router.get(
        props.url,
        { ...props.keep, range: 'custom', start, end },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <div
        :class="[
            'flex min-w-0 flex-col gap-2',
            hideCaption ? 'items-start' : 'items-stretch sm:items-end',
        ]"
        data-testid="insights-range-presets"
    >
        <div class="flex max-w-full flex-wrap items-center gap-2 sm:justify-end">
            <div
                class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg border border-border-strong p-1 sm:flex-nowrap"
                role="group"
                :aria-label="$t('analytics.ranges.label')"
            >
                <button
                    v-for="preset in presets"
                    :key="preset"
                    type="button"
                    :data-testid="`insights-range-${preset}`"
                    class="inline-flex h-6 shrink-0 items-center rounded-md px-2 text-sm font-medium whitespace-nowrap transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :class="buttonClass(preset)"
                    :aria-pressed="filters.range === preset"
                    @click="choose(preset)"
                >
                    {{ $t(`analytics.ranges.${preset}`) }}
                </button>
                <DateRangePicker
                    :model-value="selectedRange"
                    :min-date="bounds.min ? dayjs(bounds.min).toDate() : undefined"
                    :max-date="bounds.max ? dayjs(bounds.max).toDate() : undefined"
                    @update:model-value="changeRange"
                >
                    <template #trigger>
                        <button
                            type="button"
                            data-testid="insights-range-custom"
                            class="inline-flex h-6 shrink-0 items-center rounded-md px-2 text-sm font-medium whitespace-nowrap transition-control disabled:cursor-not-allowed disabled:text-subtle-foreground disabled:hover:bg-transparent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                            :class="buttonClass('custom')"
                            :aria-pressed="filters.range === 'custom'"
                            :disabled="!bounds.min"
                        >
                            {{ $t('analytics.ranges.custom') }}
                        </button>
                    </template>
                </DateRangePicker>
            </div>
        </div>
        <p
            v-if="bounds.min && !hideCaption"
            class="text-xs text-muted-foreground"
            data-testid="insights-range-caption"
        >
            {{
                $t('analytics.ranges.compared_to', {
                    current: span(range),
                    previous: span(previousRange),
                })
            }}
        </p>
    </div>
</template>
