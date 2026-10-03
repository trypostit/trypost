<script setup lang="ts">
import type { DropdownMenuSubTriggerProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { IconChevronRight } from "@tabler/icons-vue"
import {
  DropdownMenuSubTrigger,
  useForwardProps,
} from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<DropdownMenuSubTriggerProps & { class?: HTMLAttributes["class"], inset?: boolean }>()

const delegatedProps = reactiveOmit(props, "class", "inset")
const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <DropdownMenuSubTrigger
    data-slot="dropdown-menu-sub-trigger"
    v-bind="forwardedProps"
    :class="cn(
      'focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:text-subtle-foreground data-[state=open]:bg-accent data-[state=open]:text-accent-foreground flex min-h-8 cursor-pointer items-center gap-2 rounded-md p-2 text-sm leading-4 font-medium outline-hidden select-none data-[inset]:pl-8 [&_svg:not([class*=\'text-\'])]:text-muted-foreground [&_svg]:size-4 [&_svg]:shrink-0',
      props.class,
    )"
  >
    <slot />
    <IconChevronRight class="ml-auto size-4" />
  </DropdownMenuSubTrigger>
</template>
