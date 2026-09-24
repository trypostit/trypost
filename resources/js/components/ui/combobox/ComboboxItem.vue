<script setup lang="ts">
import type { ComboboxItemEmits, ComboboxItemProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  ComboboxItem,

  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<
  ComboboxItemProps & { class?: HTMLAttributes["class"] }
>()
const emits = defineEmits<ComboboxItemEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <ComboboxItem
    data-slot="combobox-item"
    v-bind="forwarded"
    :class="
      cn(
        `data-[highlighted]:bg-amber-100 data-[highlighted]:text-amber-950 data-[state=checked]:bg-amber-50 data-[state=checked]:text-amber-950 [&_svg:not([class*='text-'])]:text-muted-foreground relative flex w-full min-w-0 cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm text-foreground outline-hidden select-none transition-colors data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50 data-[state=checked]:font-medium [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4`,
        props.class,
      )
    "
  >
    <slot />
  </ComboboxItem>
</template>
