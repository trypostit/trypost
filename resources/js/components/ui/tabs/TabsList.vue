<script setup lang="ts">
import type { TabsListProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsList } from "reka-ui"
import { provide, toRef } from "vue"
import { cn } from "@/lib/utils"
import { TABS_VARIANT_KEY, type TabsVariant } from "./context"

const props = withDefaults(
  defineProps<TabsListProps & { class?: HTMLAttributes["class"]; variant?: TabsVariant }>(),
  { variant: "segmented" },
)

const delegatedProps = reactiveOmit(props, "class", "variant")

provide(TABS_VARIANT_KEY, toRef(props, "variant"))
</script>

<template>
  <TabsList
    data-slot="tabs-list"
    :data-variant="variant"
    v-bind="delegatedProps"
    :class="cn(
      variant === 'line'
        ? 'flex h-auto w-full max-w-full items-center justify-start gap-4 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-border-strong)] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden'
        : 'inline-flex h-8 w-fit max-w-full items-center gap-1 overflow-x-auto rounded-lg border border-border-strong bg-card p-[3px] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
      props.class,
    )"
  >
    <slot />
  </TabsList>
</template>
