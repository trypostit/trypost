<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import type { AcceptableValue } from 'reka-ui';
import { computed } from 'vue';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import {
    withDayEnabled,
    withoutTime,
    withTime,
} from '@/lib/postingSchedule';
import { orderedWeekdays } from '@/preferences';
import type { PostingSchedule } from '@/types/posting-schedule';

const props = defineProps<{
    schedule: PostingSchedule;
}>();

const emit = defineEmits<{
    change: [schedule: PostingSchedule];
}>();

const dayName = (day: number): string =>
    dayjs().locale(activeLocale.value.toLowerCase()).day(day).format('dddd');

const orderedSchedule = computed(() =>
    orderedWeekdays().flatMap((day) =>
        props.schedule.filter((entry) => entry.day === day),
    ),
);

const testTime = (time: string): string => time.replace(':', '');

const hours = Array.from({ length: 24 }, (_, hour) =>
    String(hour).padStart(2, '0'),
);
const minutes = Array.from({ length: 60 }, (_, minute) =>
    String(minute).padStart(2, '0'),
);

const partTriggerClass =
    'h-6 cursor-pointer gap-0 rounded-md border-0 bg-transparent px-2.5 text-sm font-medium text-muted-foreground tabular-nums shadow-none transition-control hover:bg-accent data-[state=open]:bg-accent data-[size=sm]:h-6 [&>svg]:hidden';

const toggle = (day: number, enabled: boolean): void => {
    emit('change', withDayEnabled(props.schedule, day, enabled));
};

const remove = (day: number, time: string): void => {
    emit('change', withoutTime(props.schedule, day, time));
};

const commitEdit = (
    day: number,
    time: string,
    part: 'hour' | 'minute',
    value: AcceptableValue,
): void => {
    const [hour, minute] = time.split(':');
    const next =
        part === 'hour' ? `${String(value)}:${minute}` : `${hour}:${String(value)}`;

    if (next === time) {
        return;
    }

    emit('change', withTime(withoutTime(props.schedule, day, time), day, next));
};
</script>

<template>
    <div
        class="overflow-x-auto rounded-lg border border-border bg-card"
        data-testid="schedule-grid"
    >
        <div class="grid min-w-[63rem] grid-cols-7">
            <div
                v-for="entry in orderedSchedule"
                :key="entry.day"
                class="flex min-w-0 flex-col border-e border-border last:border-e-0"
                :data-testid="`schedule-day-${entry.day}`"
            >
                <div
                    class="flex flex-col items-center gap-4 border-b border-border px-2 py-6"
                >
                    <p
                        class="text-sm font-medium whitespace-nowrap text-foreground"
                    >
                        {{ dayName(entry.day) }}
                    </p>
                    <div class="flex h-6 max-w-full min-w-0 items-center">
                        <label
                            v-if="entry.times.length > 0"
                            class="flex max-w-full min-w-0 cursor-pointer items-center gap-1 text-sm font-medium text-muted-foreground"
                        >
                            <span class="truncate">
                                {{
                                    entry.enabled
                                        ? $t('channels.settings_page.day_on')
                                        : $t('channels.settings_page.day_off')
                                }}
                            </span>
                            <Switch
                                size="sm"
                                :model-value="entry.enabled"
                                :aria-label="dayName(entry.day)"
                                :data-testid="`schedule-day-${entry.day}-toggle`"
                                @update:model-value="
                                    toggle(entry.day, Boolean($event))
                                "
                            />
                        </label>
                    </div>
                </div>

                <ul
                    :class="[
                        'flex flex-col py-4',
                        entry.enabled ? '' : 'opacity-50',
                    ]"
                >
                    <li
                        v-for="time in entry.times"
                        :key="time"
                        class="group flex h-8 items-center justify-center"
                        :data-testid="`schedule-day-${entry.day}-time-${testTime(time)}`"
                    >
                        <div class="flex items-center">
                            <Select
                                :model-value="time.slice(0, 2)"
                                @update:model-value="
                                    commitEdit(entry.day, time, 'hour', $event)
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    :class="partTriggerClass"
                                    :aria-label="
                                        $t('channels.settings_page.edit_hour', {
                                            time,
                                        })
                                    "
                                    :data-testid="`schedule-day-${entry.day}-time-${testTime(time)}-hour`"
                                >
                                    <SelectValue>{{
                                        date.formatHourPart(time.slice(0, 2))
                                    }}</SelectValue>
                                </SelectTrigger>
                                <SelectContent class="max-h-[359px]">
                                    <SelectItem
                                        v-for="hour in hours"
                                        :key="hour"
                                        :value="hour"
                                    >
                                        {{ date.formatHourOption(hour) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <span
                                class="px-0.5 text-sm font-medium text-muted-foreground"
                                >:</span
                            >
                            <Select
                                :model-value="time.slice(3, 5)"
                                @update:model-value="
                                    commitEdit(
                                        entry.day,
                                        time,
                                        'minute',
                                        $event,
                                    )
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    :class="partTriggerClass"
                                    :aria-label="
                                        $t(
                                            'channels.settings_page.edit_minute',
                                            { time },
                                        )
                                    "
                                    :data-testid="`schedule-day-${entry.day}-time-${testTime(time)}-minute`"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent class="max-h-[359px]">
                                    <SelectItem
                                        v-for="minute in minutes"
                                        :key="minute"
                                        :value="minute"
                                    >
                                        {{ minute }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <span
                                v-if="date.formatMeridiem(time.slice(0, 2))"
                                class="ps-1 text-sm font-medium text-muted-foreground"
                                :data-testid="`schedule-day-${entry.day}-time-${testTime(time)}-meridiem`"
                                >{{ date.formatMeridiem(time.slice(0, 2)) }}</span
                            >
                        </div>

                        <button
                            type="button"
                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground opacity-0 transition-opacity duration-300 ease-in-out group-hover:opacity-100 hover:bg-accent hover:text-foreground focus-visible:opacity-100 [@media(hover:none)]:opacity-100"
                            :aria-label="`${dayName(entry.day)} — ${$t('channels.settings_page.remove_time', { time })}`"
                            :data-testid="`schedule-day-${entry.day}-time-${testTime(time)}-remove`"
                            @click="remove(entry.day, time)"
                        >
                            <IconX class="size-4" />
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
