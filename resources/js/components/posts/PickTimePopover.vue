<script setup lang="ts">
import { parseDate } from '@internationalized/date';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Dialog, DialogContent, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import date from '@/date';
import dayjs from '@/dayjs';

const props = defineProps<{
    modelValue: string;
    disabled?: boolean;
    showRemove?: boolean;
}>();

const timezoneAbbr = date.getTimezoneAbbr();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    confirm: [value: string];
    remove: [];
}>();

const open = ref(false);

const parseInput = (value: string) => {
    if (!value) return undefined;
    try {
        const parsed = dayjs(value);
        if (parsed.isValid()) return parseDate(parsed.format('YYYY-MM-DD'));
    } catch {
        return undefined;
    }
    return undefined;
};

const internalDate = ref(parseInput(props.modelValue));
const selectedHour = ref(props.modelValue && dayjs(props.modelValue).isValid() ? dayjs(props.modelValue).format('HH') : '09');
const selectedMinute = ref(props.modelValue && dayjs(props.modelValue).isValid() ? dayjs(props.modelValue).format('mm') : '00');

const hours = computed(() => Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0')));
const minutes = computed(() => Array.from({ length: 12 }, (_, i) => (i * 5).toString().padStart(2, '0')));

watch(
    () => props.modelValue,
    (newVal) => {
        internalDate.value = parseInput(newVal);
        if (newVal && dayjs(newVal).isValid()) {
            const parsed = dayjs(newVal);
            selectedHour.value = parsed.format('HH');
            selectedMinute.value = parsed.format('mm');
        }
    },
);

watch(open, (isOpen) => {
    if (isOpen) {
        internalDate.value = parseInput(props.modelValue) ?? parseDate(dayjs().format('YYYY-MM-DD'));
    }
});

const buildDateTime = (): string => {
    const dateStr = internalDate.value?.toString() ?? dayjs().format('YYYY-MM-DD');
    return `${dateStr}T${selectedHour.value}:${selectedMinute.value}:00`;
};

const isPastDateTime = computed(() => dayjs(buildDateTime()).isBefore(dayjs()));

const cancel = () => {
    open.value = false;
};

const confirm = () => {
    if (isPastDateTime.value) return;
    const value = buildDateTime();
    emit('update:modelValue', value);
    emit('confirm', value);
    open.value = false;
};

const remove = () => {
    emit('remove');
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child :disabled="disabled">
            <slot :open="open" />
        </DialogTrigger>
        <DialogContent class="w-auto max-w-[calc(100%-2rem)] gap-0 p-0 sm:max-w-fit" :show-close-button="false">
            <DialogTitle class="sr-only">{{ $t('posts.edit.pick_time') }}</DialogTitle>

            <div class="flex justify-center px-3 pt-3">
                <Calendar v-model="internalDate as any" layout="month-and-year" locale="en" calendar-label="Pick a date" initial-focus />
            </div>

            <div class="border-t p-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-muted-foreground">{{ $t('posts.edit.time') }}</span>
                    <Select v-model="selectedHour">
                        <SelectTrigger class="w-[84px]"><SelectValue placeholder="HH" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="h in hours" :key="h" :value="h">{{ h }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <span class="text-muted-foreground">:</span>
                    <Select v-model="selectedMinute">
                        <SelectTrigger class="w-[84px]"><SelectValue placeholder="MM" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="m in minutes" :key="m" :value="m">{{ m }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <span v-if="timezoneAbbr" class="ml-1 text-xs text-muted-foreground">{{ timezoneAbbr }}</span>
                </div>
                <p v-if="isPastDateTime" class="mt-2 text-xs font-semibold text-rose-700">
                    {{ $t('posts.edit.pick_time_past') }}
                </p>
            </div>

            <div class="flex items-center justify-between gap-2 border-t p-3">
                <Button
                    v-if="showRemove"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="bg-rose-100 text-rose-700 hover:bg-rose-200"
                    @click="remove"
                >
                    {{ $t('posts.edit.unschedule') }}
                </Button>
                <Button v-else type="button" variant="ghost" size="sm" @click="cancel">{{ $t('posts.edit.cancel') }}</Button>
                <Button type="button" size="sm" :disabled="isPastDateTime" @click="confirm">{{ $t('posts.edit.pick_time') }}</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
