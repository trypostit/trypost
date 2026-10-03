<script setup lang="ts">
import type { DialogRootEmits, DialogRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import Command from "./Command.vue"

const props = withDefaults(defineProps<DialogRootProps & {
  title?: string
  description?: string
  showCloseButton?: boolean
  class?: HTMLAttributes["class"]
  commandClass?: HTMLAttributes["class"]
  highlightOnHover?: boolean
}>(), {
  title: "Command Palette",
  description: "Search for a command to run...",
  showCloseButton: false,
  highlightOnHover: false,
})
const emits = defineEmits<DialogRootEmits>()

defineOptions({
  inheritAttrs: false,
})

const delegatedProps = reactiveOmit(props, "class", "commandClass", "highlightOnHover", "title", "description", "showCloseButton")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <Dialog v-slot="slotProps" v-bind="forwarded">
    <DialogContent v-bind="$attrs" :class="cn('overflow-hidden p-0', props.class)" :show-close-button="showCloseButton">
      <DialogHeader class="sr-only">
        <DialogTitle>{{ title }}</DialogTitle>
        <DialogDescription>{{ description }}</DialogDescription>
      </DialogHeader>
      <Command :class="commandClass" :highlight-on-hover="highlightOnHover">
        <slot v-bind="slotProps" />
      </Command>
    </DialogContent>
  </Dialog>
</template>
