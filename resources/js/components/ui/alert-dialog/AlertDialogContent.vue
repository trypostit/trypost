<script setup lang="ts">
import type { AlertDialogContentEmits, AlertDialogContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  AlertDialogContent,
  AlertDialogOverlay,
  AlertDialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { trackDialogForTours } from "@/composables/tours/useTourWaitsForDialogs"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = defineProps<AlertDialogContentProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<AlertDialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)

trackDialogForTours()
</script>

<template>
  <AlertDialogPortal>
    <AlertDialogOverlay
      data-slot="alert-dialog-overlay"
      class="motion-dialog-overlay fixed inset-0 z-50 bg-black/50 dark:bg-black/60"
    />
    <AlertDialogContent
      data-slot="alert-dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'motion-dialog bg-background fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 max-h-[85dvh] overflow-y-auto rounded-2xl px-6 pt-6 pb-4 shadow-lg duration-200 sm:max-w-lg dark:border max-sm:inset-0 max-sm:flex max-sm:h-dvh max-sm:max-h-none max-sm:w-full max-sm:max-w-none max-sm:translate-none max-sm:flex-col max-sm:rounded-none max-sm:shadow-none max-sm:[&>:has([data-slot$=dialog-footer])]:flex max-sm:[&>:has([data-slot$=dialog-footer])]:grow max-sm:[&>:has([data-slot$=dialog-footer])]:flex-col max-sm:[&>[data-slot$=dialog-header]]:sticky max-sm:[&>[data-slot$=dialog-header]]:top-0 max-sm:[&>[data-slot$=dialog-header]]:z-10 max-sm:[&>[data-slot$=dialog-header]]:bg-background max-sm:[&>[data-slot$=dialog-header]]:shadow-[0_-1.5rem_0_1.5rem_var(--background)]',
          props.class,
        )
      "
    >
      <slot />
    </AlertDialogContent>
  </AlertDialogPortal>
</template>
