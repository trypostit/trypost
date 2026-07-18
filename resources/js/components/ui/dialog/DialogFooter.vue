<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{ class?: HTMLAttributes["class"] }>()
</script>

<template>
  <div
    data-slot="dialog-footer"
    :class="cn('flex flex-col gap-2 sm:flex-row sm:justify-start', props.class)"
  >
    <slot />
  </div>
</template>
