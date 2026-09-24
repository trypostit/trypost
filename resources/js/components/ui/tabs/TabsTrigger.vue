<script setup lang="ts">
import type { TabsTriggerProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsTrigger, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TabsTriggerProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <TabsTrigger
    data-slot="tabs-trigger"
    :class="cn(
      'inline-flex h-10 cursor-pointer items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-border bg-card px-3 text-sm font-semibold text-muted-foreground shadow-xs transition-[color,background-color,border-color,box-shadow] hover:border-amber-200 hover:bg-amber-50 hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:border-amber-300 data-[state=active]:bg-amber-200 data-[state=active]:text-amber-950 data-[state=active]:shadow-xs data-[state=active]:hover:bg-amber-300 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4',
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <slot />
  </TabsTrigger>
</template>
