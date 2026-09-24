<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
  sticky?: boolean
}>()
</script>

<template>
  <thead
    data-slot="table-header"
    :class="cn(props.sticky ? '[&_tr]:border-b-0 [&_th]:sticky [&_th]:top-0 [&_th]:z-10 [&_th]:bg-card [&_th]:shadow-[inset_0_-1px_0_var(--color-border)]' : '[&_tr]:border-b', props.class)"
  >
    <slot />
  </thead>
</template>
