<script setup lang="ts">
import type { ListboxFilterProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { IconSearch } from "@tabler/icons-vue"
import { ListboxFilter, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"
import { useCommand } from "."

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<ListboxFilterProps & {
  class?: HTMLAttributes["class"]
  wrapperClass?: HTMLAttributes["class"]
  iconClass?: HTMLAttributes["class"]
}>()

const delegatedProps = reactiveOmit(props, "class", "wrapperClass", "iconClass")

const forwardedProps = useForwardProps(delegatedProps)

const { filterState } = useCommand()
</script>

<template>
  <div
    data-slot="command-input-wrapper"
    :class="cn('flex h-10 items-center gap-2 border-b border-border px-3', props.wrapperClass)"
  >
    <IconSearch :class="cn('size-4 shrink-0 text-muted-foreground', props.iconClass)" />
    <ListboxFilter
      v-bind="{ ...forwardedProps, ...$attrs }"
      v-model="filterState.search"
      data-slot="command-input"
      auto-focus
      :class="cn('placeholder:text-muted-foreground flex h-10 w-full rounded-md bg-transparent py-3 text-sm text-foreground outline-hidden disabled:cursor-not-allowed disabled:opacity-50', props.class)"
    />
    <slot />
  </div>
</template>
