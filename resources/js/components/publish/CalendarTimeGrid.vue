<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import CalendarPostChip from '@/components/publish/CalendarPostChip.vue';
import CalendarSlotChip from '@/components/publish/CalendarSlotChip.vue';
import date from '@/date';
import dayjs from '@/dayjs';
import { calendarItems, type CalendarItem } from '@/lib/calendarItems';
import type {
    CalendarPost,
    CalendarSlot,
    PublishSocialAccount,
} from '@/types/publish';

const props = defineProps<{
    days: dayjs.Dayjs[];
    posts: Record<string, CalendarPost[]>;
    slots: Record<string, CalendarSlot[]>;
    channels: Record<string, PublishSocialAccount>;
    timezone: string;
    now: dayjs.Dayjs;
    canCreatePost: boolean;
}>();

const emit = defineEmits<{
    compose: [at: string];
}>();

const ROW_HEIGHT = 106;
const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

const scroller = ref<HTMLElement | null>(null);

const nowInZone = computed(() => props.now.tz(props.timezone));
const todayKey = computed(() => nowInZone.value.format('YYYY-MM-DD'));

const columns = computed(() =>
    props.days.map((day) => {
        const key = day.format('YYYY-MM-DD');
        const byHour = new Map<number, CalendarItem[]>();

        for (const item of calendarItems(
            props.posts[key] ?? [],
            props.slots[key] ?? [],
        )) {
            const hour = dayjs.utc(item.at).tz(props.timezone).hour();
            byHour.set(hour, [...(byHour.get(hour) ?? []), item]);
        }

        return { day, key, byHour, isToday: key === todayKey.value };
    }),
);

const MAX_CHIPS = 3;

const expandedHours = ref<string[]>([]);

const isExpanded = (key: string, hour: number): boolean =>
    expandedHours.value.includes(`${key}-${hour}`);

const expandHour = (key: string, hour: number): void => {
    expandedHours.value = [...expandedHours.value, `${key}-${hour}`];
};

const visibleItems = (
    key: string,
    hour: number,
    items: CalendarItem[],
): CalendarItem[] =>
    items.length > MAX_CHIPS && !isExpanded(key, hour)
        ? items.slice(0, MAX_CHIPS - 1)
        : items;

const slotStart = (key: string, hour: number): dayjs.Dayjs =>
    dayjs.tz(`${key} ${String(hour).padStart(2, '0')}:00`, props.timezone);

const isPastSlot = (key: string, hour: number): boolean =>
    slotStart(key, hour).isBefore(props.now);

const hourLabel = (hour: number): string =>
    `${String(hour).padStart(2, '0')}`;

const compose = (key: string, hour: number): void => {
    emit('compose', slotStart(key, hour).utc().format());
};

const scrollToCurrentHour = (): void => {
    if (scroller.value) {
        scroller.value.scrollTop = nowInZone.value.hour() * ROW_HEIGHT;
    }
};

onMounted(scrollToCurrentHour);

watch(
    () => props.days[0]?.format('YYYY-MM-DD'),
    () => {
        expandedHours.value = [];
        nextTick(scrollToCurrentHour);
    },
);
</script>

<template>
    <div
        ref="scroller"
        class="flex-1 overflow-y-auto overscroll-contain"
        data-testid="calendar-time-grid"
    >
        <div
            class="sticky top-0 z-20 grid border-b border-border-strong bg-card"
            :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }"
        >
            <div
                v-for="(column, index) in columns"
                :key="column.key"
                class="-mb-px flex items-center justify-center gap-3 border-b p-2.5 text-sm font-medium capitalize"
                :class="[
                    index > 0 ? 'border-l border-l-border-strong' : '',
                    column.isToday
                        ? 'border-b-primary-text text-primary-text'
                        : 'border-b-transparent text-muted-foreground',
                ]"
                :data-testid="`calendar-column-${column.key}`"
            >
                <span>{{ column.day.format('dddd') }}</span>
                <span>{{ column.day.format('D') }}</span>
            </div>
        </div>

        <div
            class="grid"
            :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }"
        >
            <div
                v-for="(column, index) in columns"
                :key="column.key"
                class="relative"
                :class="{ 'border-l border-border-strong': index > 0 }"
            >
                <div
                    v-for="hour in HOURS"
                    :key="hour"
                    class="group relative flex items-start justify-between gap-1 px-3 py-2"
                    :class="{
                        'bg-accent': isPastSlot(column.key, hour),
                        'border-t border-border-strong':
                            hour > 0 && (index > 0 || hour % 2 === 1),
                    }"
                    :style="{ height: `${ROW_HEIGHT}px` }"
                    :data-testid="`calendar-slot-${column.key}-${hourLabel(hour)}`"
                >
                    <div
                        v-if="index === 0 && hour > 0 && hour % 2 === 0"
                        class="pointer-events-none absolute inset-x-0 top-0 z-10 flex h-px items-center"
                        aria-hidden="true"
                    >
                        <span
                            class="px-2 text-xs leading-[15px] font-medium text-muted-foreground"
                            >{{ date.formatHourOption(hourLabel(hour)) }}</span
                        >
                        <span class="h-px flex-1 bg-border-strong" />
                    </div>

                    <div
                        class="flex min-w-0 flex-1 gap-1"
                        :class="
                            isExpanded(column.key, hour)
                                ? 'max-h-full flex-col overflow-y-auto overscroll-contain'
                                : 'flex-nowrap'
                        "
                    >
                        <template
                            v-for="item in visibleItems(
                                column.key,
                                hour,
                                column.byHour.get(hour) ?? [],
                            )"
                            :key="item.key"
                        >
                            <CalendarPostChip
                                v-if="item.post"
                                :post="item.post"
                                :timezone="timezone"
                                layout="week"
                                class="flex-1"
                                :class="{
                                    'min-h-7 grow-0': isExpanded(
                                        column.key,
                                        hour,
                                    ),
                                }"
                            />
                            <CalendarSlotChip
                                v-else-if="item.slot"
                                :posting-slot="item.slot"
                                :channel="channels[item.slot.channel_id] ?? null"
                                :timezone="timezone"
                                :can-create-post="canCreatePost"
                                class="flex-1"
                                :class="{
                                    'min-h-7 grow-0': isExpanded(
                                        column.key,
                                        hour,
                                    ),
                                }"
                            />
                        </template>
                        <button
                            v-if="
                                !isExpanded(column.key, hour) &&
                                (column.byHour.get(hour)?.length ?? 0) >
                                    MAX_CHIPS
                            "
                            type="button"
                            class="flex h-7 shrink-0 items-center rounded-lg px-1.5 text-xs font-medium text-muted-foreground transition-control hover:bg-secondary hover:text-foreground"
                            :data-testid="`calendar-more-${column.key}-${hourLabel(hour)}`"
                            @click="expandHour(column.key, hour)"
                        >
                            +{{
                                (column.byHour.get(hour)?.length ?? 0) -
                                MAX_CHIPS +
                                1
                            }}
                        </button>
                    </div>

                    <button
                        v-if="canCreatePost && !isPastSlot(column.key, hour)"
                        type="button"
                        :aria-label="$t('calendar.new_post')"
                        class="flex size-6 shrink-0 items-center justify-center rounded-md border border-border-strong bg-card text-muted-foreground opacity-0 transition-opacity duration-100 ease-in-out group-hover:opacity-100 hover:text-foreground focus-visible:opacity-100 [@media(hover:none)]:opacity-100"
                        :data-testid="`calendar-add-${column.key}-${hourLabel(hour)}`"
                        @click="compose(column.key, hour)"
                    >
                        <IconPlus class="size-3.5" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
