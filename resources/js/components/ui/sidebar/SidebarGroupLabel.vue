<script setup lang="ts">
import type { PrimitiveProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<PrimitiveProps & {
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <Primitive data-slot="sidebar-group-label" data-sidebar="group-label" :as="as" :as-child="asChild" :class="cn(
    'text-muted-foreground ring-sidebar-ring flex h-8 shrink-0 items-center rounded-md pl-2 text-xs font-normal outline-hidden transition-[margin,opacity] duration-(--motion-duration-sidebar-toggle) ease-(--motion-easing-sidebar-toggle) focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0',
    'group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0',
    props.class)">
    <slot />
  </Primitive>
</template>
