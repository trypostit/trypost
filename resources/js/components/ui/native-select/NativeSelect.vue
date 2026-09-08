<script setup lang="ts">
import type { AcceptableValue } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit, useVModel } from "@vueuse/core"
import { IconChevronDown } from "@tabler/icons-vue"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{ modelValue?: AcceptableValue | AcceptableValue[], class?: HTMLAttributes["class"] }>()

const emit = defineEmits<{
  "update:modelValue": AcceptableValue
}>()

const modelValue = useVModel(props, "modelValue", emit, {
  passive: true,
  defaultValue: "",
})

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <div
    class="group/native-select relative w-fit has-[select:disabled]:opacity-50"
    data-slot="native-select-wrapper"
  >
    <select
      v-bind="{ ...$attrs, ...delegatedProps }"
      v-model="modelValue"
      data-slot="native-select"
      :class="cn(
        'h-9 w-full min-w-0 cursor-pointer appearance-none rounded-md border-2 border-foreground bg-card px-3 py-2 pr-9 text-sm font-medium text-foreground shadow-2xs transition-[color,box-shadow] outline-none placeholder:text-foreground/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
        'focus-visible:ring-foreground/20 focus-visible:ring-[3px]',
        'aria-invalid:border-destructive',
        props.class,
      )"
    >
      <slot />
    </select>
    <IconChevronDown
      class="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 select-none text-foreground/60"
      aria-hidden="true"
      data-slot="native-select-icon"
    />
  </div>
</template>
