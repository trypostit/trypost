<script setup lang="ts">
import type { DropdownMenuCheckboxItemEmits, DropdownMenuCheckboxItemProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { IconCheck } from "@tabler/icons-vue"
import {
  DropdownMenuCheckboxItem,
  DropdownMenuItemIndicator,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<DropdownMenuCheckboxItemProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<DropdownMenuCheckboxItemEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DropdownMenuCheckboxItem
    data-slot="dropdown-menu-checkbox-item"
    v-bind="forwarded"
    :class=" cn(
      'focus:bg-accent focus:text-accent-foreground relative flex min-h-8 items-center gap-2 rounded-md py-2 pr-3 pl-3 text-sm leading-4 font-medium outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:text-subtle-foreground [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4',
      props.class,
    )"
  >
    <slot />
    <span class="pointer-events-none ms-auto flex size-4 items-center justify-center">
      <DropdownMenuItemIndicator>
        <slot name="indicator-icon">
          <IconCheck class="size-4" />
        </slot>
      </DropdownMenuItemIndicator>
    </span>
  </DropdownMenuCheckboxItem>
</template>
