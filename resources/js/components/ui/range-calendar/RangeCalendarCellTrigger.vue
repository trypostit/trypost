<script lang="ts" setup>
import type { RangeCalendarCellTriggerProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { RangeCalendarCellTrigger, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"
import { buttonVariants } from '@/components/ui/button'

const props = withDefaults(defineProps<RangeCalendarCellTriggerProps & { class?: HTMLAttributes["class"] }>(), {
  as: "button",
})

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <RangeCalendarCellTrigger
    data-slot="range-calendar-trigger"
    :class="cn(
      buttonVariants({ variant: 'ghost' }),
      'size-8 cursor-pointer p-0 font-medium hover:bg-foreground/5 data-[selected]:opacity-100',
      // Today (not selected): warm highlight
      '[&[data-today]:not([data-selected])]:bg-amber-100 [&[data-today]:not([data-selected])]:text-amber-950 [&[data-today]:not([data-selected])]:font-semibold',
      // Selection start: brand pill
      'data-[selection-start]:bg-primary data-[selection-start]:text-primary-foreground data-[selection-start]:font-bold data-[selection-start]:hover:bg-primary data-[selection-start]:hover:text-primary-foreground data-[selection-start]:focus:bg-primary data-[selection-start]:focus:text-primary-foreground',
      // Selection end
      'data-[selection-end]:bg-primary data-[selection-end]:text-primary-foreground data-[selection-end]:font-bold data-[selection-end]:hover:bg-primary data-[selection-end]:hover:text-primary-foreground data-[selection-end]:focus:bg-primary data-[selection-end]:focus:text-primary-foreground',
      // Outside months
      'data-[outside-view]:text-foreground/40',
      // Disabled
      'data-[disabled]:text-foreground/40 data-[disabled]:opacity-50',
      // Unavailable
      'data-[unavailable]:text-destructive-foreground data-[unavailable]:line-through',
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <slot />
  </RangeCalendarCellTrigger>
</template>
