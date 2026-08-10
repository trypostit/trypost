<script setup lang="ts">
import { parseDate } from '@internationalized/date';
import { IconCalendar } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref, watch, computed } from 'vue';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCalendarLocale } from '@/composables/useCalendarLocale';
import dayjs from '@/dayjs';

const props = defineProps({
    name: {
        type: String,
        default: '',
    },
    modelValue: {
        type: String,
        default: '',
    },
    align: {
        type: String as () => 'start' | 'center' | 'end',
        default: 'end',
        validator: (value: string) => ['start', 'center', 'end'].includes(value),
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    showTime: {
        type: Boolean,
        default: true,
    },
    placeholder: {
        type: String,
        default: '',
    },
});

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const calendarLocale = useCalendarLocale();

// Parse input value into date
const parseInput = (value: string) => {
    if (!value) return undefined;

    try {
        const date = dayjs(value);
        if (date.isValid()) {
            return parseDate(date.format('YYYY-MM-DD'));
        }
    } catch {
        return undefined;
    }
    return undefined;
};

const internalDate = ref(parseInput(props.modelValue));
const popoverOpen = ref(false);

// Time state (24-hour format)
const selectedHour = ref(
    props.modelValue && dayjs(props.modelValue).isValid()
        ? dayjs(props.modelValue).format('HH')
        : '09',
);
const selectedMinute = ref(
    props.modelValue && dayjs(props.modelValue).isValid()
        ? dayjs(props.modelValue).format('mm')
        : '00',
);

// Generate hours (00-23)
const hours = computed(() => {
    return Array.from({ length: 24 }, (_, i) => {
        return i.toString().padStart(2, '0');
    });
});

// Generate minutes (00-59 in 5-minute intervals)
const minutes = computed(() => {
    return Array.from({ length: 12 }, (_, i) => {
        return (i * 5).toString().padStart(2, '0');
    });
});

// Build full datetime string
const buildDateTime = (dateStr: string | null): string | null => {
    if (!dateStr) return null;

    if (!props.showTime) {
        return dateStr;
    }

    const timeStr = `${selectedHour.value}:${selectedMinute.value}:00`;
    return `${dateStr}T${timeStr}`;
};

const onTimeChange = () => {
    if (internalDate.value) {
        const dateStr = internalDate.value.toString();
        emit('update:modelValue', buildDateTime(dateStr));
    }
};

// Parse input value into date component
const isInternalUpdate = ref(false);

watch(
    () => props.modelValue,
    (newVal) => {
        if (isInternalUpdate.value) {
            isInternalUpdate.value = false;
            return;
        }

        internalDate.value = parseInput(newVal);

        if (newVal) {
            const parsed = dayjs(newVal);
            if (parsed.isValid()) {
                if (props.showTime) {
                    selectedHour.value = parsed.format('HH');
                    selectedMinute.value = parsed.format('mm');
                }
            }
        } else {
            selectedHour.value = '09';
            selectedMinute.value = '00';
        }
    },
    { immediate: true },
);

// Watch internal date changes from calendar
watch(internalDate, (newDate) => {
    if (!newDate || isInternalUpdate.value) return;

    isInternalUpdate.value = true;
    const formatted = newDate.toString();
    emit('update:modelValue', buildDateTime(formatted));

    if (!props.showTime) {
        popoverOpen.value = false;
    }
});

// Display string for the button — LL/LLL follow the active dayjs locale.
const displayText = computed(() => {
    if (!props.modelValue || !dayjs(props.modelValue).isValid()) {
        return null;
    }

    const parsed = dayjs(props.modelValue);

    if (props.showTime) {
        return parsed.format('LLL');
    }

    return parsed.format('LL');
});
</script>

<template>
    <Popover v-model:open="popoverOpen">
        <PopoverTrigger as-child :disabled="disabled">
            <Button :id="name" type="button" variant="outline" class="w-full justify-between text-left font-medium"
                :class="[{ 'text-foreground/60': !displayText }, $attrs.class]" :disabled="disabled">
                <span>{{ displayText || placeholder || $t('common.date_picker.select') }}</span>
                <IconCalendar class="size-4 shrink-0" />
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" :align="align">
            <Calendar
                v-model="internalDate as any"
                :placeholder="(internalDate as any)"
                layout="month-and-year"
                :locale="calendarLocale"
                :calendar-label="trans('common.date_picker.label')"
                initial-focus
            />
            <!-- Time Picker -->
            <div v-if="showTime" class="border-t-2 border-foreground/10 p-3">
                <div class="flex items-center gap-2">
                    <Select v-model="selectedHour" @update:model-value="onTimeChange">
                        <SelectTrigger class="w-[80px]">
                            <SelectValue placeholder="HH" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="h in hours" :key="h" :value="h">
                                {{ h }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <span class="font-bold text-foreground/40">:</span>
                    <Select v-model="selectedMinute" @update:model-value="onTimeChange">
                        <SelectTrigger class="w-[80px]">
                            <SelectValue placeholder="MM" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="m in minutes" :key="m" :value="m">
                                {{ m }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
