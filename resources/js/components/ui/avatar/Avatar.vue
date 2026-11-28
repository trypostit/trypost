<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { computed } from "vue"
import { AvatarRoot } from "reka-ui"
import { cn } from "@/lib/utils"
import { getInitials } from "@/composables/app/useInitials"

const props = defineProps<{
  class?: HTMLAttributes["class"]
  src?: string | null
  name?: string
  fallbackClass?: HTMLAttributes["class"]
}>()

const hasProps = computed(() => props.src !== undefined || props.name !== undefined)
</script>

<template>
  <AvatarRoot
    data-slot="avatar"
    :class="cn('relative flex size-8 shrink-0 overflow-hidden rounded-full', props.class)"
  >
    <template v-if="hasProps">
      <img
        v-if="src"
        :src="src"
        :alt="name"
        class="aspect-square size-full object-cover"
      />
      <div
        v-else
        :class="cn('flex size-full items-center justify-center bg-muted font-medium', props.fallbackClass)"
      >
        {{ name ? getInitials(name) : '' }}
      </div>
    </template>
    <slot v-else />
  </AvatarRoot>
</template>
