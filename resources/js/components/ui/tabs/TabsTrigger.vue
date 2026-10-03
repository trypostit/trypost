<script setup lang="ts">
import type { TabsTriggerProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsTrigger, useForwardProps } from "reka-ui"
import { inject, ref } from "vue"
import { cn } from "@/lib/utils"
import { TABS_VARIANT_KEY } from "./context"

const props = defineProps<TabsTriggerProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)

const variant = inject(TABS_VARIANT_KEY, ref("segmented" as const))
</script>

<template>
  <TabsTrigger
    data-slot="tabs-trigger"
    :class="cn(
      variant === 'line'
        ? 'relative inline-flex h-[45px] shrink-0 cursor-pointer items-center justify-center gap-2 whitespace-nowrap px-2 text-sm font-medium text-muted-foreground transition-control after:absolute after:inset-x-0.5 after:bottom-0 after:h-px after:bg-primary-text after:opacity-0 hover:text-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:text-subtle-foreground data-[state=active]:text-foreground data-[state=active]:after:opacity-100 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4'
        : 'inline-flex h-6 cursor-pointer items-center justify-center gap-1 whitespace-nowrap rounded-md px-2 text-sm font-medium text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-primary-selected data-[state=active]:text-primary-text data-[state=active]:hover:bg-primary-selected [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4',
      props.class,
    )"
    v-bind="forwardedProps"
  >
    <slot />
  </TabsTrigger>
</template>
