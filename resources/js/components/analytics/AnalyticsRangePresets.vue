<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { nextTick, ref, watch } from 'vue';

import { DateRangePicker } from '@/components/ui/date-range-picker';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    keep?: Record<string, string | string[]>;
}>();

const presets: Exclude<AnalyticsRangePreset, 'custom'>[] = [
    '7d',
    '30d',
    'mtd',
];
const buttonClass = (preset: AnalyticsRangePreset): string =>
    props.filters.range === preset
        ? 'bg-primary-selected text-primary-text'
        : 'text-muted-foreground hover:bg-accent hover:text-foreground';
const toDates = (start: string, end: string): { start: Date; end: Date } => ({
    start: dayjs(start).toDate(),
    end: dayjs(end).toDate(),
});
const mobileTriggerLabelKey = (): string => `analytics.ranges.${props.filters.range}`;
const selectedRange = ref(toDates(props.range.start, props.range.end));
const customOpen = ref(false);

watch(
    () => [props.range.start, props.range.end],
    ([start, end]) => {
        selectedRange.value = toDates(start, end);
    },
);

const choose = (preset: AnalyticsRangePreset): void => {
    router.get(props.url, { ...props.keep, range: preset });
};

/** On a phone, Custom is the menu's last option and opens the calendar sheet once the menu has closed. */
const openCustom = async (): Promise<void> => {
    await nextTick();
    customOpen.value = true;
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
        class="flex min-w-0 flex-col items-start gap-2"
        data-testid="insights-range-presets"
    >
        <div class="flex max-w-full flex-wrap items-center gap-2 sm:justify-end">
            <div class="flex items-center gap-2 sm:hidden">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            data-testid="insights-range-mobile-trigger"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border-strong bg-background px-3 text-sm font-medium whitespace-nowrap text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                            :aria-label="$t('analytics.ranges.label')"
                        >
                            {{ $t(mobileTriggerLabelKey()) }}
                            <IconChevronDown class="size-4 text-muted-foreground" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem
                            v-for="preset in presets"
                            :key="preset"
                            :data-testid="`insights-range-mobile-${preset}`"
                            :aria-checked="filters.range === preset"
                            @select="choose(preset)"
                        >
                            {{ $t(`analytics.ranges.${preset}`) }}
                            <IconCheck
                                v-if="filters.range === preset"
                                :data-testid="`insights-range-mobile-${preset}-check`"
                                class="ms-auto size-4 text-primary-text"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            data-testid="insights-range-mobile-custom"
                            :aria-checked="filters.range === 'custom'"
                            :disabled="!bounds.min"
                            @select="openCustom"
                        >
                            {{ $t('analytics.ranges.custom') }}
                            <IconCheck
                                v-if="filters.range === 'custom'"
                                class="ms-auto size-4 text-primary-text"
                            />
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <DateRangePicker
                    v-model:open="customOpen"
                    :model-value="selectedRange"
                    :min-date="bounds.min ? dayjs(bounds.min).toDate() : undefined"
                    :max-date="bounds.max ? dayjs(bounds.max).toDate() : undefined"
                    sheet-below-sm
                    @update:model-value="changeRange"
                >
                    <template #trigger>
                        <span aria-hidden="true" />
                    </template>
                </DateRangePicker>
            </div>
            <div
                class="hidden max-w-full flex-wrap items-center gap-1 rounded-lg border border-border-strong p-1 sm:inline-flex sm:flex-nowrap"
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
                    align="start"
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
    </div>
</template>
