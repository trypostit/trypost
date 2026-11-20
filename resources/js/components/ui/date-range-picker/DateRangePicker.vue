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
import { createReusableTemplate, useWindowSize } from "@vueuse/core"
import BottomSheet from "@/components/BottomSheet.vue"
import { Button } from "@/components/ui/button"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { RangeCalendar } from "@/components/ui/range-calendar"
import { useBelowBreakpoint } from "@/composables/useBreakpoint"
import { useCalendarLocale } from "@/composables/useCalendarLocale"
import { cn } from "@/lib/utils"
import dayjs from "@/dayjs"
import date from "@/date"

const props = withDefaults(defineProps<{
  modelValue: { start: Date, end: Date }
  triggerClass?: string
  minDate?: Date
  maxDate?: Date
  disabled?: boolean
  align?: "start" | "center" | "end"
  sheetBelowSm?: boolean
  /** Opened only through `v-model:open`, as from a menu item, with no button of its own. */
  withoutTrigger?: boolean
}>(), {
  align: "end",
  sheetBelowSm: false,
  withoutTrigger: false,
})

const [DefinePanel, ReusePanel] = createReusableTemplate()
const [DefineMonth, ReuseMonth] = createReusableTemplate<{ fill?: boolean }>()

/** The phone sheet's month: every day stretched over the card, like the calendar's period sheet. */
const FILLED_MONTH = [
  "w-full p-0",
  "[&_[data-slot=range-calendar-header]]:justify-start [&_[data-slot=range-calendar-header]]:gap-2",
  "[&_[data-slot=range-calendar-heading]]:order-first [&_[data-slot=range-calendar-heading]]:me-auto [&_[data-slot=range-calendar-heading]]:text-base",
  "[&_[data-slot=range-calendar-grid]]:w-full",
  "[&_[data-slot=range-calendar-head-cell]]:flex-1 [&_[data-slot=range-calendar-head-cell]]:text-xs",
  "[&_[data-slot=range-calendar-grid-row]]:mt-1",
  "[&_[data-slot=range-calendar-cell]]:flex [&_[data-slot=range-calendar-cell]]:flex-1 [&_[data-slot=range-calendar-cell]]:justify-center",
  "[&_[data-slot=range-calendar-trigger]]:size-10 [&_[data-slot=range-calendar-trigger]]:text-base",
].join(" ")
const belowSm = useBelowBreakpoint("sm")
const asSheet = computed(() => props.sheetBelowSm && belowSm.value)

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
const isOpen = defineModel<boolean>("open", { default: false })
const { width } = useWindowSize()
const numberOfMonths = computed(() => width.value < 640 ? 1 : 2)
const minimum = computed(() => props.minDate ? toCalendarDate(props.minDate) : undefined)
const maximum = computed(() => props.maxDate ? toCalendarDate(props.maxDate) : undefined)
const latestAvailableDay = computed(() => {
  const today = dayjs().startOf("day")
  const latest = props.maxDate ? dayjs(props.maxDate).startOf("day") : today
  return latest.isBefore(today) ? latest : today
})

const range = (start: dayjs.Dayjs, end: dayjs.Dayjs) => ({
  start: toCalendarDate(start.toDate()),
  end: toCalendarDate(end.toDate()),
})

type Preset = { key: string, label: string, getValue: () => { start: CalendarDate, end: CalendarDate } }

const presetGroups = computed<Preset[][]>(() => [
  [
    { key: 'today', label: trans('common.date_range_picker.today'), getValue: () => range(dayjs(), dayjs()) },
    { key: 'yesterday', label: trans('common.date_range_picker.yesterday'), getValue: () => range(dayjs().subtract(1, "day"), dayjs().subtract(1, "day")) },
  ],
  [
    { key: 'last_7_days', label: trans('common.date_range_picker.last_7_days'), getValue: () => range(latestAvailableDay.value.subtract(6, "day"), latestAvailableDay.value) },
    { key: 'last_30_days', label: trans('common.date_range_picker.last_30_days'), getValue: () => range(latestAvailableDay.value.subtract(29, "day"), latestAvailableDay.value) },
    { key: 'last_3_months', label: trans('common.date_range_picker.last_3_months'), getValue: () => range(latestAvailableDay.value.subtract(3, "month"), latestAvailableDay.value) },
    { key: 'last_6_months', label: trans('common.date_range_picker.last_6_months'), getValue: () => range(latestAvailableDay.value.subtract(6, "month"), latestAvailableDay.value) },
    { key: 'last_12_months', label: trans('common.date_range_picker.last_12_months'), getValue: () => range(latestAvailableDay.value.subtract(12, "month").add(1, "day"), latestAvailableDay.value) },
  ],
  [
    { key: 'this_month', label: trans('common.date_range_picker.this_month'), getValue: () => range(dayjs().startOf("month"), dayjs().endOf("month")) },
    { key: 'last_month', label: trans('common.date_range_picker.last_month'), getValue: () => range(dayjs().subtract(1, "month").startOf("month"), dayjs().subtract(1, "month").endOf("month")) },
    { key: 'year_to_date', label: trans('common.date_range_picker.year_to_date'), getValue: () => range(dayjs().startOf("year"), dayjs()) },
    { key: 'last_year', label: trans('common.date_range_picker.last_year'), getValue: () => range(dayjs().subtract(1, "year").startOf("year"), dayjs().subtract(1, "year").endOf("year")) },
  ],
].map(group => group.filter(preset => {
  const selected = preset.getValue()
  return (!minimum.value || selected.end.compare(minimum.value) >= 0)
    && (!maximum.value || selected.start.compare(maximum.value) <= 0)
})).filter(group => group.length > 0))

const applyPreset = (preset: Preset) => {
  const selected = preset.getValue()
  const clamp = (date: CalendarDate) => {
    if (minimum.value && date.compare(minimum.value) < 0) return minimum.value
    if (maximum.value && date.compare(maximum.value) > 0) return maximum.value
    return date
  }
  value.value = { start: clamp(selected.start), end: clamp(selected.end) }
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
  <DefineMonth v-slot="{ fill }">
    <RangeCalendar
      v-model="value"
      initial-focus
      :class="{ [FILLED_MONTH]: fill }"
      :locale="calendarLocale"
      :number-of-months="numberOfMonths"
      :min-value="minimum"
      :max-value="maximum"
    />
  </DefineMonth>

  <DefinePanel>
      <div class="flex flex-col sm:flex-row">
        <div class="hidden flex-col border-b border-border py-2 sm:flex sm:w-[170px] sm:shrink-0 sm:border-r sm:border-b-0">
          <template v-for="(group, groupIndex) in presetGroups" :key="groupIndex">
            <div v-if="groupIndex > 0" class="my-1 border-t border-border" />
            <div class="space-y-0.5 px-2">
              <Button
                v-for="preset in group"
                :key="preset.key"
                :data-testid="`date-range-preset-${preset.key}`"
                variant="ghost"
                size="sm"
                class="h-7 w-full justify-start text-xs font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                @click="applyPreset(preset)"
              >
                {{ preset.label }}
              </Button>
            </div>
          </template>
        </div>

        <div class="flex shrink-0 justify-center">
          <ReuseMonth />
        </div>
      </div>
  </DefinePanel>

  <Popover v-model:open="isOpen">
    <PopoverTrigger v-if="!withoutTrigger" as-child>
      <slot name="trigger">
        <Button
          variant="outline"
          data-testid="date-range-picker-trigger"
          :disabled="disabled"
          :class="cn(
            'w-full justify-start text-left font-medium sm:w-auto',
            !value && 'text-foreground/60',
            props.triggerClass,
          )"
        >
          <template v-if="value.start">
            <template v-if="value.end">
              {{ date.formatLocalDate(toDate(value.start)) }} -
              {{ date.formatLocalDate(toDate(value.end)) }}
            </template>
            <template v-else>
              {{ date.formatLocalDate(toDate(value.start)) }}
            </template>
          </template>
          <template v-else>
            {{ $t('common.date_range_picker.placeholder') }}
          </template>
          <IconCalendar class="ml-auto size-4 text-foreground/60" />
        </Button>
      </slot>
    </PopoverTrigger>
    <PopoverContent v-if="!asSheet" class="w-auto p-0" :align="align">
      <ReusePanel />
    </PopoverContent>
  </Popover>
  <BottomSheet
    v-if="asSheet"
    v-model:open="isOpen"
    :title="$t('common.date_range_picker.placeholder')"
    :show-header="false"
    handle
    test-id="date-range-picker-sheet"
    content-class="bg-muted px-4 pb-[max(1.5rem,env(safe-area-inset-bottom))]"
  >
    <p class="mb-4 text-center text-base font-emphasis text-foreground">
      {{ $t('common.date_range_picker.placeholder') }}
    </p>
    <div class="rounded-xl bg-card p-4" data-testid="date-range-picker-month">
      <ReuseMonth :fill="true" />
    </div>
  </BottomSheet>
</template>
