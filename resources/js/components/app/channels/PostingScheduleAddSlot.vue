<script setup lang="ts">
import { IconTrash } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import {
    canAddTo,
    MAX_TIMES_PER_DAY,
    targetDays,
    type DayTarget,
} from '@/lib/app/postingSchedule';
import { orderedWeekdays } from '@/preferences';
import type { PostingSchedule } from '@/types/app/posting-schedule';

const props = defineProps<{
    schedule: PostingSchedule;
    timezone: string;
}>();

const emit = defineEmits<{
    add: [days: number[], time: string];
    clear: [];
}>();

const weekdayName = (day: number): string =>
    dayjs().locale(activeLocale.value.toLowerCase()).day(day).format('dddd');

const addTarget = ref<string>('every_day');
const addTargets = computed(() => [
    ...(['every_day', 'weekdays', 'weekends'] as const).map((target) => ({
        value: target,
        labelKey: `channels.settings_page.targets.${target}`,
        label: '',
    })),
    ...orderedWeekdays().map((day) => ({
        value: String(day),
        labelKey: null,
        label: weekdayName(day),
    })),
]);
const selectedTarget = computed(() =>
    addTargets.value.find((option) => option.value === addTarget.value),
);
const currentTime = () => dayjs().tz(props.timezone);
const addHour = ref(String(currentTime().hour()).padStart(2, '0'));
const addMinute = ref(String(currentTime().minute()).padStart(2, '0'));

watch(
    () => props.timezone,
    () => {
        addHour.value = String(currentTime().hour()).padStart(2, '0');
        addMinute.value = String(currentTime().minute()).padStart(2, '0');
    },
);

const addTriggerClass =
    'h-10 gap-2 rounded-lg border-border-strong data-[size=default]:h-10 max-md:w-full bg-transparent px-4 font-medium transition-control hover:bg-accent data-[state=open]:bg-accent [&_svg]:opacity-100';

const hours = Array.from({ length: 24 }, (_, hour) =>
    String(hour).padStart(2, '0'),
);
const minutes = Array.from({ length: 60 }, (_, minute) =>
    String(minute).padStart(2, '0'),
);

const addDays = computed(() =>
    targetDays(
        /^\d$/.test(addTarget.value)
            ? Number(addTarget.value)
            : (addTarget.value as DayTarget),
    ),
);
const canAdd = computed(() => canAddTo(props.schedule, addDays.value));

const addSlot = (): void => {
    emit('add', addDays.value, `${addHour.value}:${addMinute.value}`);
};

const clearOpen = ref(false);

const openClearDialog = (): void => {
    clearOpen.value = true;
};

const closeClearDialog = (): void => {
    clearOpen.value = false;
};

const clearAll = (): void => {
    clearOpen.value = false;
    emit('clear');
};
</script>

<template>
    <div
        class="grid grid-cols-2 gap-2 text-sm font-medium md:flex md:flex-wrap md:items-center"
    >
        <span class="col-span-2">{{
            $t('channels.settings_page.add_prefix')
        }}</span>
        <Select v-model="addTarget">
            <SelectTrigger
                :class="[addTriggerClass, 'col-span-2']"
                :aria-label="$t('channels.settings_page.add_prefix')"
                data-testid="schedule-add-target"
            >
                <SelectValue>{{
                    selectedTarget?.labelKey
                        ? $t(selectedTarget.labelKey)
                        : selectedTarget?.label
                }}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="target in addTargets"
                    :key="target.value"
                    :value="target.value"
                    :data-testid="`schedule-add-target-option-${target.value}`"
                >
                    {{ target.labelKey ? $t(target.labelKey) : target.label }}
                </SelectItem>
            </SelectContent>
        </Select>
        <span class="max-md:hidden">{{
            $t('channels.settings_page.add_at')
        }}</span>
        <Select v-model="addHour">
            <SelectTrigger
                :class="[addTriggerClass, 'tabular-nums']"
                :aria-label="$t('channels.settings_page.add_at')"
                data-testid="schedule-add-hour"
            >
                <SelectValue>{{ date.formatHourOption(addHour) }}</SelectValue>
            </SelectTrigger>
            <SelectContent class="max-h-[359px]">
                <SelectItem
                    v-for="hour in hours"
                    :key="hour"
                    :value="hour"
                    :data-testid="`schedule-add-hour-option-${hour}`"
                >
                    {{ date.formatHourOption(hour) }}
                </SelectItem>
            </SelectContent>
        </Select>
        <Select v-model="addMinute">
            <SelectTrigger
                :class="[addTriggerClass, 'tabular-nums']"
                :aria-label="$t('channels.settings_page.add_at')"
                data-testid="schedule-add-minute"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent class="max-h-[359px]">
                <SelectItem
                    v-for="minute in minutes"
                    :key="minute"
                    :value="minute"
                    :data-testid="`schedule-add-minute-option-${minute}`"
                >
                    {{ minute }}
                </SelectItem>
            </SelectContent>
        </Select>
        <Button
            v-if="canAdd"
            type="button"
            size="lg"
            class="col-span-2 md:col-span-1"
            data-testid="schedule-add-submit"
            @click="addSlot"
        >
            {{ $t('channels.settings_page.add_button') }}
        </Button>
        <span
            v-else
            class="col-span-2 font-normal text-muted-foreground"
            data-testid="schedule-add-limit"
        >
            {{
                $t('channels.settings_page.add_limit', {
                    count: String(MAX_TIMES_PER_DAY),
                })
            }}
        </span>

        <Button
            type="button"
            variant="ghost"
            size="lg"
            class="col-span-2 justify-self-start text-destructive-text hover:text-destructive-text md:col-span-1 md:ms-auto"
            data-testid="schedule-clear"
            @click="openClearDialog"
        >
            <IconTrash class="size-4 text-destructive-text" />
            {{ $t('channels.settings_page.clear_all') }}
        </Button>

        <Dialog v-model:open="clearOpen">
            <DialogContent :show-close-button="false">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('channels.settings_page.clear_confirm_title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t('channels.settings_page.clear_confirm_description')
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="closeClearDialog"
                    >
                        {{ $t('channels.settings_page.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="schedule-clear-confirm"
                        @click="clearAll"
                    >
                        {{ $t('channels.settings_page.clear_all') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
