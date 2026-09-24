<script setup lang="ts">
import type { TagsInputItemDeleteProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { IconX } from "@tabler/icons-vue"
import { TagsInputItemDelete, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TagsInputItemDeleteProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <TagsInputItemDelete
    v-bind="forwardedProps"
    :class="cn('mr-1 inline-flex size-5 cursor-pointer items-center justify-center rounded-sm bg-transparent text-amber-900/60 transition-colors hover:bg-amber-200 hover:text-amber-950 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring', props.class)"
  >
    <slot>
      <IconX class="size-3.5" />
    </slot>
  </TagsInputItemDelete>
</template>
