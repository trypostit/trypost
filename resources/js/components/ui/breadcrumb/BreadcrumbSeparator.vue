<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { IconChevronRight } from "@tabler/icons-vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <li
    data-slot="breadcrumb-separator"
    role="presentation"
    aria-hidden="true"
    :class="cn('[&>svg]:size-3.5', props.class)"
  >
    <slot>
      <IconChevronRight />
    </slot>
  </li>
</template>
