<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { IconMinus } from "@tabler/icons-vue"
import { useForwardProps } from "reka-ui"

const props = defineProps<{ class?: HTMLAttributes["class"] }>()

const forwarded = useForwardProps(props)
</script>

<template>
  <div
    data-slot="input-otp-separator"
    role="separator"
    v-bind="forwarded"
  >
    <slot>
      <IconMinus />
    </slot>
  </div>
</template>
