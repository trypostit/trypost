<script setup lang="ts">
import type { DateRange } from "reka-ui"
import type { Ref } from "vue"
import {
  CalendarDate,
  getLocalTimeZone,
} from "@internationalized/date"
import { IconCalendar } from "@tabler/icons-vue"
import { trans } from "laravel-vue-i18n"
import { computed, nextTick, ref, watch } from "vue"
import { useWindowSize } from "@vueuse/core"
import { Button } from "@/components/ui/button"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { RangeCalendar } from "@/components/ui/range-calendar"
import { useCalendarLocale } from "@/composables/useCalendarLocale"
import { cn } from "@/lib/utils"
import dayjs from "@/dayjs"

const props = defineProps<{
  modelValue: { start: Date, end: Date }
  triggerClass?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: { start: Date, end: Date }]
}>()

const calendarLocale = useCalendarLocale()

const toCalendarDate = (dateValue: Date) => {
  return new CalendarDate(
    dateValue.getFullYear(),
    dateValue.getMonth() + 1,
    dateValue.getDate(),
  )
}

const toDate = (calendarDate: any) => {
  if (!calendarDate) return new Date()
  return calendarDate.toDate(getLocalTimeZone())
}

const value = ref({
  start: toCalendarDate(props.modelValue.start),
  end: toCalendarDate(props.modelValue.end),
}) as Ref<DateRange>

const isUpdating = ref(false)
const isOpen = ref(false)
const { width } = useWindowSize()
const numberOfMonths = computed(() => width.value < 640 ? 1 : 2)

const range = (start: dayjs.Dayjs, end: dayjs.Dayjs) => ({
  start: toCalendarDate(start.toDate()),
  end: toCalendarDate(end.toDate()),
})

const presetGroups = computed(() => [
  [
    { label: trans('common.date_range_picker.today'), getValue: () => range(dayjs(), dayjs()) },
    { label: trans('common.date_range_picker.yesterday'), getValue: () => range(dayjs().subtract(1, "day"), dayjs().subtract(1, "day")) },
  ],
  [
    { label: trans('common.date_range_picker.last_7_days'), getValue: () => range(dayjs().subtract(6, "day"), dayjs()) },
    { label: trans('common.date_range_picker.last_30_days'), getValue: () => range(dayjs().subtract(29, "day"), dayjs()) },
    { label: trans('common.date_range_picker.last_3_months'), getValue: () => range(dayjs().subtract(3, "month"), dayjs()) },
    { label: trans('common.date_range_picker.last_6_months'), getValue: () => range(dayjs().subtract(6, "month"), dayjs()) },
    { label: trans('common.date_range_picker.last_12_months'), getValue: () => range(dayjs().subtract(12, "month").add(1, "day"), dayjs()) },
  ],
  [
    { label: trans('common.date_range_picker.this_month'), getValue: () => range(dayjs().startOf("month"), dayjs().endOf("month")) },
    { label: trans('common.date_range_picker.last_month'), getValue: () => range(dayjs().subtract(1, "month").startOf("month"), dayjs().subtract(1, "month").endOf("month")) },
    { label: trans('common.date_range_picker.year_to_date'), getValue: () => range(dayjs().startOf("year"), dayjs()) },
    { label: trans('common.date_range_picker.last_year'), getValue: () => range(dayjs().subtract(1, "year").startOf("year"), dayjs().subtract(1, "year").endOf("year")) },
  ],
])

type Preset = { label: string, getValue: () => { start: any, end: any } }

const applyPreset = (preset: Preset) => {
  value.value = preset.getValue()
  isOpen.value = false
}

watch(
  () => props.modelValue,
  (newVal) => {
    if (!isUpdating.value) {
      value.value = {
        start: toCalendarDate(newVal.start),
        end: toCalendarDate(newVal.end),
      }
    }
  },
  { deep: true },
)

watch(
  value,
  (newVal) => {
    if (newVal.start && newVal.end) {
      isUpdating.value = true
      emit("update:modelValue", {
        start: toDate(newVal.start),
        end: toDate(newVal.end),
      })
      nextTick(() => {
        isUpdating.value = false
      })
    }
  },
  { deep: true },
)
</script>

<template>
  <Popover v-model:open="isOpen">
    <PopoverTrigger as-child>
      <Button
        variant="outline"
        :class="cn(
          'w-full justify-start text-left font-medium sm:w-auto',
          !value && 'text-foreground/60',
          props.triggerClass,
        )"
      >
        <template v-if="value.start">
          <template v-if="value.end">
            {{ dayjs(toDate(value.start)).format('LL') }} -
            {{ dayjs(toDate(value.end)).format('LL') }}
          </template>
          <template v-else>
            {{ dayjs(toDate(value.start)).format('LL') }}
          </template>
        </template>
        <template v-else>
          {{ $t('common.date_range_picker.placeholder') }}
        </template>
        <IconCalendar class="ml-auto size-4 text-foreground/60" />
      </Button>
    </PopoverTrigger>
    <PopoverContent class="w-auto p-0" align="end">
      <div class="flex flex-col sm:flex-row">
        <div class="hidden flex-col border-b-2 border-foreground py-2 sm:flex sm:w-[170px] sm:shrink-0 sm:border-b-0 sm:border-r-2">
          <template v-for="(group, groupIndex) in presetGroups" :key="groupIndex">
            <div v-if="groupIndex > 0" class="my-1 border-t-2 border-dashed border-foreground/20" />
            <div class="space-y-0.5 px-2">
              <Button
                v-for="preset in group"
                :key="preset.label"
                variant="ghost"
                size="sm"
                class="h-7 w-full justify-start text-xs font-bold text-foreground hover:bg-violet-100 hover:text-foreground"
                @click="applyPreset(preset)"
              >
                {{ preset.label }}
              </Button>
            </div>
          </template>
        </div>

        <div class="shrink-0">
          <RangeCalendar
            v-model="value"
            initial-focus
            :locale="calendarLocale"
            :number-of-months="numberOfMonths"
          />
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
