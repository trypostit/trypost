<script setup lang="ts">
import { IconChevronDown, IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import CalendarPostChip from '@/components/publish/CalendarPostChip.vue';
import CalendarSlotChip from '@/components/publish/CalendarSlotChip.vue';
import {
    calendarTargetKey,
    useCalendarDragContext,
} from '@/composables/calendar/useCalendarDrag';
import dayjs from '@/dayjs';
import { DRAFT_DAY_CLOCK, type CalendarDropTarget } from '@/lib/calendarDrop';
import {
    calendarDayKey as dayKey,
    calendarItems,
    type CalendarItem,
} from '@/lib/calendarItems';
import type {
    CalendarPost,
    CalendarSlot,
    PublishSocialAccount,
} from '@/types/publish';

const props = defineProps<{
    weeks: dayjs.Dayjs[][];
    month: dayjs.Dayjs;
    posts: Record<string, CalendarPost[]>;
    slots: Record<string, CalendarSlot[]>;
    channels: Record<string, PublishSocialAccount>;
    timezone: string;
    now: dayjs.Dayjs;
    canCreatePost: boolean;
    isDesktop: boolean;
}>();

const emit = defineEmits<{
    compose: [at: string];
}>();

const expandedDays = defineModel<string[]>('expandedDays', { required: true });

/**
 * How many items a day shows before "+N more": a phone's shorter cells fit one less.
 */
const monthChips = computed(() => (props.isDesktop ? 4 : 3));

const { overTarget, draggingPostId, vCalendarTarget } =
    useCalendarDragContext();

const todayKey = computed(() =>
    props.now.tz(props.timezone).format('YYYY-MM-DD'),
);

const weekdayNames = computed(() =>
    (props.weeks[0] ?? []).map((day) =>
        day.format(props.isDesktop ? 'dddd' : 'ddd'),
    ),
);

const dayTargetKey = (day: dayjs.Dayjs): string =>
    calendarTargetKey({ kind: 'day', dayKey: dayKey(day) });

const isDayOver = (day: dayjs.Dayjs): boolean =>
    overTarget.value === dayTargetKey(day);

const isToday = (day: dayjs.Dayjs): boolean => dayKey(day) === todayKey.value;

const isPast = (day: dayjs.Dayjs): boolean => dayKey(day) < todayKey.value;

const isCurrentMonth = (day: dayjs.Dayjs): boolean =>
    day.month() === props.month.month();

const itemsByDay = computed<Record<string, CalendarItem[]>>(() =>
    Object.fromEntries(
        props.weeks
            .flat()
            .map((day) => [
                dayKey(day),
                calendarItems(
                    props.posts[dayKey(day)] ?? [],
                    props.slots[dayKey(day)] ?? [],
                ),
            ]),
    ),
);

const dayTargets = computed<Record<string, CalendarDropTarget>>(() =>
    Object.fromEntries(
        props.weeks
            .flat()
            .map((day) => [dayKey(day), { kind: 'day', dayKey: dayKey(day) }]),
    ),
);

const itemsFor = (day: dayjs.Dayjs): CalendarItem[] =>
    itemsByDay.value[dayKey(day)] ?? [];

const composeOn = (day: dayjs.Dayjs): void =>
    emit(
        'compose',
        dayjs
            .tz(`${dayKey(day)}T${DRAFT_DAY_CLOCK}`, props.timezone)
            .utc()
            .format(),
    );

const toggleDay = (key: string): void => {
    expandedDays.value = expandedDays.value.includes(key)
        ? expandedDays.value.filter((day) => day !== key)
        : [...expandedDays.value, key];
};

const isDayExpanded = (day: dayjs.Dayjs): boolean =>
    expandedDays.value.includes(dayKey(day));

const hiddenCount = (day: dayjs.Dayjs): number =>
    itemsFor(day).length - monthChips.value;

const visibleMonthItems = (day: dayjs.Dayjs): CalendarItem[] =>
    isDayExpanded(day)
        ? itemsFor(day)
        : itemsFor(day).slice(0, monthChips.value);
</script>

<template>
    <div
        class="flex flex-1 flex-col overflow-y-auto overscroll-contain"
        data-calendar-scroller
        data-testid="calendar-month-grid"
    >
        <div
            class="sticky top-0 z-10 grid grid-cols-7 border-b border-border-strong bg-card"
        >
            <div
                v-for="(name, index) in weekdayNames"
                :key="name"
                class="border-border-strong p-1.5 text-center text-sm font-medium text-muted-foreground capitalize md:p-2.5"
                :class="{ 'border-l': index > 0 }"
            >
                {{ name }}
            </div>
        </div>

        <div class="flex flex-1 flex-col">
            <div
                v-for="(week, weekIndex) in weeks"
                :key="weekIndex"
                class="grid min-h-40 flex-1 auto-rows-[minmax(0,1fr)] grid-cols-7 border-border-strong md:min-h-[208px]"
                :class="{ 'border-t': weekIndex > 0 }"
            >
                <div
                    v-for="(day, index) in week"
                    :key="dayKey(day)"
                    v-calendar-target="dayTargets[dayKey(day)]"
                    class="group flex min-h-0 min-w-0 flex-col gap-1 border-border-strong p-1 md:p-2"
                    :class="{
                        'border-l': index > 0,
                        'bg-accent': isPast(day),
                        'bg-primary/15': isDayOver(day),
                    }"
                    :data-drop-over="isDayOver(day) || undefined"
                    :data-testid="`calendar-day-${dayKey(day)}`"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="inline-flex size-6 items-center justify-center rounded-full text-sm font-medium"
                            :class="{
                                'bg-primary text-primary-foreground':
                                    isToday(day),
                                'text-subtle-foreground':
                                    !isToday(day) &&
                                    !isCurrentMonth(day),
                                'text-muted-foreground':
                                    !isToday(day) &&
                                    isCurrentMonth(day),
                            }"
                        >
                            {{ day.format('D') }}
                        </span>
                        <button
                            v-if="
                                canCreatePost &&
                                !isPast(day) &&
                                !draggingPostId
                            "
                            type="button"
                            :aria-label="$t('calendar.new_post')"
                            class="flex size-6 items-center justify-center rounded-md border border-border-strong bg-card text-muted-foreground opacity-0 transition-opacity duration-100 ease-in-out group-hover:opacity-100 hover:text-foreground focus-visible:opacity-100 max-md:hidden [@media(hover:none)]:opacity-100"
                            :data-testid="`calendar-add-${dayKey(day)}`"
                            @click="composeOn(day)"
                        >
                            <IconPlus class="size-3.5" />
                        </button>
                    </div>

                    <div class="relative min-h-0 flex-1">
                    <div
                        class="flex flex-col gap-1"
                        :class="{
                            'absolute inset-0 overflow-y-auto overscroll-contain':
                                isDayExpanded(day),
                        }"
                        :data-testid="`calendar-items-${dayKey(day)}`"
                    >
                        <template
                            v-for="item in visibleMonthItems(day)"
                            :key="item.key"
                        >
                            <CalendarPostChip
                                v-if="item.post"
                                :post="item.post"
                                :timezone="timezone"
                                :compact="!isDesktop"
                                :class="{
                                    'opacity-50':
                                        isDayOver(day) &&
                                        item.post.id !==
                                            draggingPostId,
                                }"
                            />
                            <CalendarSlotChip
                                v-else-if="item.slot"
                                :posting-slot="item.slot"
                                :channel="
                                    channels[
                                        item.slot.channel_id
                                    ] ?? null
                                "
                                :timezone="timezone"
                                :can-create-post="canCreatePost"
                                :compact="!isDesktop"
                            />
                        </template>
                    </div>
                    </div>
                    <button
                        v-if="hiddenCount(day) > 0"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md text-xs font-medium text-foreground transition-control hover:bg-accent"
                        :class="
                            isDesktop
                                ? 'h-6 self-end px-2'
                                : 'h-7 min-w-7 justify-center self-start px-1'
                        "
                        :aria-expanded="isDayExpanded(day)"
                        :aria-label="
                            isDesktop
                                ? undefined
                                : isDayExpanded(day)
                                  ? $t('calendar.less')
                                  : $t('calendar.more', {
                                        count: String(
                                            hiddenCount(day),
                                        ),
                                    })
                        "
                        :data-testid="`calendar-more-${dayKey(day)}`"
                        @click="toggleDay(dayKey(day))"
                    >
                        <IconChevronDown
                            v-if="isDesktop || isDayExpanded(day)"
                            class="size-3.5 transition-transform"
                            :class="{
                                'rotate-180': isDayExpanded(day),
                            }"
                        />
                        <template v-if="isDesktop">{{
                            isDayExpanded(day)
                                ? $t('calendar.less')
                                : $t('calendar.more', {
                                      count: String(
                                          hiddenCount(day),
                                      ),
                                  })
                        }}</template>
                        <template v-else-if="!isDayExpanded(day)"
                            >+{{ hiddenCount(day) }}</template
                        >
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
