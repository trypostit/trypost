<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { type DateValue, parseDate } from '@internationalized/date';
import {
    IconCalendarEvent,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
} from '@tabler/icons-vue';
import { computed, ref, useTemplateRef } from 'vue';

import ResponsivePopover from '@/components/ResponsivePopover.vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { useCalendarLocale } from '@/composables/useCalendarLocale';
import type { CalendarView } from '@/types/publish';

const props = defineProps<{
    view: CalendarView;
    views: readonly CalendarView[];
    title: string;
    selectedDayKey: string;
    viewHref: (view: CalendarView) => string;
    reloadProps: string[];
}>();

const emit = defineEmits<{
    navigate: [direction: number];
    today: [];
    changeView: [view: CalendarView];
    pickDay: [dayKey: string];
}>();

const compact = useBelowBreakpoint('md');
const calendarLocale = useCalendarLocale();
const open = ref(false);

const desktopViews = computed(() =>
    props.views.filter((option) => option !== 'days'),
);

const selectedDate = computed(() => parseDate(props.selectedDayKey));

const close = (): void => {
    open.value = false;
};

const changeView = (view: CalendarView): void => {
    emit('changeView', view);
};

const goToToday = (): void => {
    close();
    emit('today');
};

const pickDay = (value: DateValue | undefined): void => {
    if (!value) {
        return;
    }

    close();
    emit('pickDay', value.toString());
};

const titleElement = useTemplateRef<HTMLHeadingElement>('titleElement');

const missingTitleWidth = (): number =>
    titleElement.value
        ? titleElement.value.scrollWidth - titleElement.value.clientWidth
        : 0;

defineExpose({ missingTitleWidth });
</script>

<template>
    <ResponsivePopover
        v-if="compact"
        v-model:open="open"
        :title="$t('calendar.title')"
        test-id="calendar-period-picker"
        align="start"
        content-class="w-80 bg-muted p-3"
        sheet-class="bg-muted px-4 pb-6"
    >
        <template #trigger>
            <button
                type="button"
                class="-ms-2 inline-flex h-9 min-w-0 items-center gap-1.5 rounded-lg px-2 font-heading text-base font-medium text-foreground capitalize transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                data-testid="calendar-period-trigger"
            >
                <span class="truncate" data-testid="calendar-title">{{
                    title
                }}</span>
                <IconChevronDown class="size-4 shrink-0 text-muted-foreground" />
            </button>
        </template>

        <p
            class="mb-4 text-center text-base font-emphasis text-foreground sm:hidden"
        >
            {{ $t('calendar.title') }}
        </p>
        <div
            class="flex rounded-lg border border-border-strong bg-card p-[3px]"
            role="group"
            :aria-label="$t('calendar.title')"
        >
            <button
                v-for="option in views"
                :key="option"
                type="button"
                :aria-pressed="view === option"
                class="h-9 flex-1 rounded-md text-sm font-medium transition-control"
                :class="
                    view === option
                        ? 'bg-primary-selected text-primary-text'
                        : 'text-foreground hover:bg-accent'
                "
                :data-testid="`calendar-view-${option}`"
                @click="changeView(option)"
            >
                {{ $t(`calendar.${option}`) }}
            </button>
        </div>

        <div
            class="mt-4 rounded-xl bg-card p-4"
            data-testid="calendar-period-calendar"
        >
            <Calendar
                class="w-full p-0 [&_[data-slot=calendar-cell]]:flex-1 [&_[data-slot=calendar-cell]]:flex [&_[data-slot=calendar-cell]]:justify-center [&_[data-slot=calendar-cell]:has([data-selected])]:bg-transparent [&_[data-slot=calendar-cell-trigger]]:size-10 [&_[data-slot=calendar-cell-trigger]]:text-base [&_[data-slot=calendar-grid-row]]:mt-1 [&_[data-slot=calendar-header]]:justify-start [&_[data-slot=calendar-header]]:px-0 [&_[data-slot=calendar-header]]:text-base [&_[data-slot=calendar-head-cell]]:text-xs [&_[data-slot=calendar-header]_nav]:justify-end"
                :model-value="selectedDate"
                :placeholder="selectedDate"
                :locale="calendarLocale"
                :calendar-label="$t('calendar.title')"
                @update:model-value="pickDay"
            />

            <div class="mt-4 border-t border-border pt-4">
                <Button
                    variant="outline"
                    class="w-full"
                    data-testid="calendar-today"
                    @click="goToToday"
                >
                    <IconCalendarEvent class="size-4" />
                    {{ $t('calendar.today') }}
                </Button>
            </div>
        </div>
    </ResponsivePopover>

    <div v-else class="flex h-12 min-w-0 items-center gap-2">
        <div class="flex min-w-0 items-center">
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0"
                :aria-label="$t('calendar.previous')"
                data-testid="calendar-previous"
                @click="emit('navigate', -1)"
            >
                <IconChevronLeft class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0"
                :aria-label="$t('calendar.next')"
                data-testid="calendar-next"
                @click="emit('navigate', 1)"
            >
                <IconChevronRight class="size-4" />
            </Button>
            <h2
                ref="titleElement"
                class="ms-1 truncate font-heading text-base leading-5 font-medium text-foreground capitalize"
                data-testid="calendar-title"
            >
                {{ title }}
            </h2>
        </div>
        <Button
            variant="outline"
            class="shrink-0"
            data-testid="calendar-today"
            @click="goToToday"
        >
            {{ $t('calendar.today') }}
        </Button>
        <nav
            class="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-border-strong bg-card p-[3px]"
            :aria-label="$t('calendar.title')"
        >
            <Link
                v-for="option in desktopViews"
                :key="option"
                :href="viewHref(option)"
                :only="reloadProps"
                preserve-state
                preserve-scroll
                :aria-current="view === option ? 'page' : undefined"
                class="inline-flex h-6 items-center rounded-md border border-transparent px-2 text-sm font-medium transition-control"
                :class="
                    view === option
                        ? 'bg-primary-selected text-primary-text'
                        : 'text-foreground hover:bg-accent'
                "
                :data-testid="`calendar-view-${option}`"
            >
                {{ $t(`calendar.${option}`) }}
            </Link>
        </nav>
    </div>
</template>
