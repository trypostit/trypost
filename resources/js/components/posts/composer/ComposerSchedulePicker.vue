<script setup lang="ts">
import { parseDate, today, type DateValue } from '@internationalized/date';
import {
    IconArrowLeft,
    IconCheck,
    IconChevronLeft,
    IconChevronRight,
    IconClock,
} from '@tabler/icons-vue';
import {
    CalendarHeading,
    CalendarNext,
    CalendarPrev,
    CalendarRoot,
} from 'reka-ui';
import { computed, nextTick, ref, shallowRef } from 'vue';

import {
    CalendarCell,
    CalendarCellTrigger,
    CalendarGrid,
    CalendarGridBody,
    CalendarGridHead,
    CalendarGridRow,
    CalendarHeadCell,
} from '@/components/ui/calendar';
import { useCalendarLocale } from '@/composables/useCalendarLocale';
import date from '@/date';
import dayjs from '@/dayjs';
import { weekStartIndex } from '@/preferences';
import type { PostingSchedule } from '@/types/posting-schedule';

const props = defineProps<{
    modelValue: string;
    timezone: string;
    postingSchedule?: PostingSchedule | null;
    takenSlots?: string[];
}>();

const emit = defineEmits<{
    confirm: [value: string];
    back: [];
}>();

const calendarLocale = useCalendarLocale();
const timezoneLabel = computed(() => props.timezone.replaceAll('_', ' '));
const minDate = today(props.timezone);

const initial = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(props.modelValue)
    ? props.modelValue
    : date.nextFullHour(props.timezone);

const pickedDate = shallowRef<DateValue>(parseDate(initial.slice(0, 10)));
const pickedTime = ref(initial.slice(11, 16));
const timeText = ref(date.formatClockTime(pickedTime.value));
const timeListOpen = ref(false);
const highlightedTime = ref<string | null>(null);
const timeList = ref<HTMLElement | null>(null);

const pad = (value: number): string => String(value).padStart(2, '0');

const timeOptions = computed(() =>
    Array.from({ length: 96 }, (_, index) => {
        const value = `${pad(Math.floor(index / 4))}:${pad((index % 4) * 15)}`;

        return { value, label: date.formatClockTime(value) };
    }),
);

const pickedDay = computed(() => pickedDate.value.toString());

const slotTimes = computed<string[]>(() => {
    const entry = props.postingSchedule?.find(
        (day) => day.day === dayjs(pickedDay.value).day(),
    );

    return entry?.enabled ? entry.times : [];
});

const isPastTime = (time: string): boolean =>
    dayjs.tz(`${pickedDay.value}T${time}`, props.timezone).isBefore(dayjs());

const isPast = computed(() => isPastTime(pickedTime.value));

const takenInstants = computed(
    () => new Set((props.takenSlots ?? []).map((at) => dayjs(at).valueOf())),
);

const isUnavailableSlot = (time: string): boolean =>
    isPastTime(time) ||
    takenInstants.value.has(
        dayjs.tz(`${pickedDay.value}T${time}`, props.timezone).valueOf(),
    );

const hasOpenSlot = computed(() =>
    slotTimes.value.some((time) => !isUnavailableSlot(time)),
);

/**
 * Read a typed time: the label of a list option, or a loose clock such as
 * "9", "930", "17:15" or "5:15 pm".
 */
const parseTypedTime = (text: string): string | null => {
    const normalized = text.trim().toLowerCase();
    const option = timeOptions.value.find(
        (candidate) => candidate.label.toLowerCase() === normalized,
    );
    if (option) return option.value;

    const match = normalized
        .replace(/\s+/g, '')
        .match(/^(\d{1,2})(?::?(\d{2}))?(?:([ap])\.?m?\.?)?$/);
    if (!match) return null;

    let hour = Number(match[1]);
    const minute = Number(match[2] ?? 0);
    const meridiem = match[3];
    if (minute > 59) return null;
    if (meridiem) {
        if (hour < 1 || hour > 12) return null;
        hour = (hour % 12) + (meridiem === 'p' ? 12 : 0);
    } else if (hour > 23) {
        return null;
    }

    return `${pad(hour)}:${pad(minute)}`;
};

const nearestSlot = (time: string): string => {
    const [hour, minute] = time.split(':').map(Number);

    return `${pad(hour)}:${pad(Math.floor(minute / 15) * 15)}`;
};

const scrollToHighlighted = (): void => {
    nextTick(() => {
        const list = timeList.value;
        const item = list?.querySelector<HTMLElement>('[data-highlighted]');
        if (!list || !item) return;
        list.scrollTop =
            item.offsetTop - list.clientHeight / 2 + item.offsetHeight / 2;
    });
};

const openTimeList = (): void => {
    highlightedTime.value = nearestSlot(pickedTime.value);
    timeListOpen.value = true;
    scrollToHighlighted();
};

const onTimeInput = (event: Event): void => {
    timeText.value = (event.target as HTMLInputElement).value;
    const parsed = parseTypedTime(timeText.value);
    if (parsed) {
        pickedTime.value = parsed;
        highlightedTime.value = nearestSlot(parsed);
        scrollToHighlighted();
    }
    timeListOpen.value = true;
};

const commitTimeText = (): void => {
    const parsed = parseTypedTime(timeText.value);
    if (parsed) pickedTime.value = parsed;
    timeText.value = date.formatClockTime(pickedTime.value);
    timeListOpen.value = false;
};

const selectTime = (time: string): void => {
    pickedTime.value = time;
    timeText.value = date.formatClockTime(time);
    timeListOpen.value = false;
};

const moveHighlight = (step: number): void => {
    if (!timeListOpen.value) {
        openTimeList();
        return;
    }
    const values = timeOptions.value.map((option) => option.value);
    const current = values.indexOf(
        highlightedTime.value ?? nearestSlot(pickedTime.value),
    );
    highlightedTime.value =
        values[Math.min(values.length - 1, Math.max(0, current + step))];
    scrollToHighlighted();
};

const onTimeEnter = (): void => {
    if (
        timeListOpen.value &&
        highlightedTime.value &&
        !parseTypedTime(timeText.value)
    ) {
        selectTime(highlightedTime.value);
        return;
    }
    commitTimeText();
};

const onTimeEscape = (event: KeyboardEvent): void => {
    if (!timeListOpen.value) return;
    event.stopPropagation();
    commitTimeText();
};

const onDateChange = (value: DateValue | undefined): void => {
    if (value) pickedDate.value = value;
};

const done = (): void => {
    commitTimeText();
    if (isPast.value) return;
    emit('confirm', `${pickedDay.value}T${pickedTime.value}`);
};
</script>

<template>
    <div data-testid="composer-schedule-picker" class="text-popover-foreground">
        <div class="relative px-4 pt-4 pb-3">
            <CalendarRoot
                v-slot="{ grid, weekDays }"
                :model-value="pickedDate"
                :min-value="minDate"
                :locale="calendarLocale"
                :week-starts-on="weekStartIndex()"
                weekday-format="narrow"
                fixed-weeks
                prevent-deselect
                data-testid="composer-schedule-calendar"
                @update:model-value="onDateChange"
            >
                <div class="mb-3 flex items-center justify-between">
                    <CalendarHeading
                        class="ps-1 text-sm font-emphasis text-foreground capitalize"
                        data-testid="composer-schedule-calendar-heading"
                    />
                    <div class="flex items-center gap-1">
                        <CalendarPrev
                            class="flex size-7 cursor-pointer items-center justify-center rounded-md text-foreground transition-control outline-none hover:bg-accent focus-visible:bg-accent disabled:cursor-not-allowed disabled:opacity-40"
                            data-testid="composer-schedule-calendar-prev"
                        >
                            <IconChevronLeft class="size-4 rtl:rotate-180" />
                        </CalendarPrev>
                        <CalendarNext
                            class="flex size-7 cursor-pointer items-center justify-center rounded-md text-foreground transition-control outline-none hover:bg-accent focus-visible:bg-accent"
                            data-testid="composer-schedule-calendar-next"
                        >
                            <IconChevronRight class="size-4 rtl:rotate-180" />
                        </CalendarNext>
                    </div>
                </div>
                <CalendarGrid
                    v-for="month in grid"
                    :key="month.value.toString()"
                    class="space-x-0"
                >
                    <CalendarGridHead>
                        <CalendarGridRow class="flex">
                            <CalendarHeadCell
                                v-for="(day, index) in weekDays"
                                :key="index"
                                class="pb-1 text-sm font-emphasis tracking-normal text-foreground normal-case"
                                data-testid="composer-schedule-weekday"
                                >{{ day }}</CalendarHeadCell
                            >
                        </CalendarGridRow>
                    </CalendarGridHead>
                    <CalendarGridBody>
                        <CalendarGridRow
                            v-for="(weekDates, index) in month.rows"
                            :key="`week-${index}`"
                            class="mt-1 flex w-full"
                        >
                            <CalendarCell
                                v-for="weekDate in weekDates"
                                :key="weekDate.toString()"
                                :date="weekDate"
                                class="flex flex-1 justify-center [&:has([data-selected])]:bg-transparent"
                            >
                                <CalendarCellTrigger
                                    :day="weekDate"
                                    :month="month.value"
                                    :data-testid="`composer-schedule-day-${weekDate.toString()}`"
                                    :data-zone-today="
                                        weekDate.compare(minDate) === 0
                                            ? ''
                                            : undefined
                                    "
                                    class="font-emphasis [&[data-today]:not([data-selected])]:bg-transparent [&[data-today]:not([data-selected]):not([data-zone-today])]:font-emphasis! [&[data-zone-today]:not([data-selected])]:font-semibold"
                                />
                            </CalendarCell>
                        </CalendarGridRow>
                    </CalendarGridBody>
                </CalendarGrid>
            </CalendarRoot>
        </div>

        <div class="relative mx-4 border-t border-border-strong pt-3 pb-4">
            <div
                v-if="hasOpenSlot"
                class="mb-3"
                data-testid="composer-schedule-slots"
            >
                <p class="mb-1.5 text-xs font-emphasis text-foreground">
                    {{ $t('channels.settings_page.slots_title') }}
                </p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="time in slotTimes"
                        :key="time"
                        type="button"
                        :disabled="isUnavailableSlot(time)"
                        :aria-pressed="time === pickedTime"
                        :data-testid="`composer-schedule-slot-${time.replace(':', '')}`"
                        class="h-7 rounded-md border border-border-strong px-2 text-xs font-emphasis transition-control outline-none disabled:cursor-not-allowed disabled:opacity-40"
                        :class="
                            time === pickedTime
                                ? 'bg-primary-subtle text-primary-text'
                                : 'text-foreground enabled:hover:bg-accent'
                        "
                        @click="selectTime(time)"
                    >
                        {{ date.formatClockTime(time) }}
                    </button>
                </div>
            </div>
            <label
                for="composer-schedule-time"
                class="mb-1.5 block text-xs font-emphasis text-foreground"
                >{{ $t('posts.composer.schedule_picker.select_time') }}</label
            >
            <div class="relative">
                <div
                    v-if="timeListOpen"
                    ref="timeList"
                    role="listbox"
                    :aria-label="$t('posts.composer.schedule_picker.select_time')"
                    data-testid="composer-schedule-time-list"
                    class="absolute inset-x-0 bottom-full z-10 mb-1.5 max-h-64 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-md"
                >
                    <button
                        v-for="option in timeOptions"
                        :key="option.value"
                        type="button"
                        role="option"
                        tabindex="-1"
                        :aria-selected="option.value === pickedTime"
                        :data-highlighted="
                            option.value === highlightedTime ? '' : undefined
                        "
                        :disabled="isPastTime(option.value)"
                        :data-testid="`composer-schedule-time-option-${option.value.replace(':', '')}`"
                        class="block w-full rounded-md px-3 py-2.5 text-start text-sm transition-control outline-none disabled:cursor-not-allowed disabled:opacity-40"
                        :class="
                            option.value === pickedTime
                                ? 'bg-primary-subtle font-emphasis text-primary-text'
                                : option.value === highlightedTime
                                  ? 'bg-accent text-foreground'
                                  : 'text-foreground enabled:hover:bg-accent'
                        "
                        @mousedown.prevent
                        @click="selectTime(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <div
                    class="flex h-9 items-center gap-2 rounded-lg border border-border-strong bg-background px-2.5 transition-control focus-within:border-primary-strong focus-within:bg-primary-subtle focus-within:text-primary-text focus-within:ring-1 focus-within:ring-primary-strong"
                    :class="{ 'border-destructive': isPast }"
                >
                    <IconClock class="size-4 shrink-0" />
                    <input
                        id="composer-schedule-time"
                        :value="timeText"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        role="combobox"
                        :aria-expanded="timeListOpen"
                        data-testid="composer-schedule-time-input"
                        class="h-full min-w-0 flex-1 bg-transparent text-sm outline-none"
                        @focus="openTimeList"
                        @click="timeListOpen || openTimeList()"
                        @input="onTimeInput"
                        @blur="commitTimeText"
                        @keydown.enter.prevent="onTimeEnter"
                        @keydown.down.prevent="moveHighlight(1)"
                        @keydown.up.prevent="moveHighlight(-1)"
                        @keydown.esc="onTimeEscape"
                    />
                    <span
                        class="shrink-0 truncate text-xs"
                        data-testid="composer-schedule-timezone"
                        >{{ timezoneLabel }}</span
                    >
                </div>
            </div>
            <p
                v-if="isPast"
                class="mt-2 text-xs text-destructive-text"
                data-testid="composer-schedule-past"
            >
                {{ $t('posts.edit.pick_time_past') }}
            </p>
        </div>

        <div
            class="flex items-center justify-between gap-2 border-t border-border-strong px-2 py-2"
        >
            <button
                type="button"
                data-testid="composer-schedule-more-actions"
                class="flex h-9 items-center gap-1.5 rounded-md px-2.5 text-sm font-emphasis text-foreground transition-control outline-none hover:bg-accent focus-visible:bg-accent"
                @click="emit('back')"
            >
                <IconArrowLeft class="size-4 rtl:rotate-180" />{{
                    $t('posts.composer.schedule_picker.more_actions')
                }}
            </button>
            <button
                type="button"
                data-testid="composer-schedule-done"
                :disabled="isPast"
                class="flex h-9 items-center gap-1.5 rounded-md px-2.5 text-sm font-emphasis text-foreground transition-control outline-none hover:bg-accent focus-visible:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
                @click="done"
            >
                <IconCheck class="size-4" />{{
                    $t('posts.composer.schedule_picker.done')
                }}
            </button>
        </div>
    </div>
</template>
