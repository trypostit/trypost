<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { IconX } from "@tabler/icons-vue"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import DialogOverlay from "./DialogOverlay.vue"

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<DialogContentProps & { class?: HTMLAttributes["class"], showCloseButton?: boolean, fullscreenOnMobile?: boolean }>(), {
  showCloseButton: true,
  fullscreenOnMobile: true,
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class", "fullscreenOnMobile")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DialogPortal>
    <DialogOverlay />
    <DialogContent
      data-slot="dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="
        cn(
          'motion-dialog bg-background fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 max-h-[85dvh] overflow-y-auto rounded-2xl px-6 pt-6 pb-4 shadow-lg duration-200 sm:max-w-lg dark:border',
          fullscreenOnMobile && 'max-sm:inset-0 max-sm:flex max-sm:h-dvh max-sm:max-h-none max-sm:w-full max-sm:max-w-none max-sm:translate-none max-sm:flex-col max-sm:rounded-none max-sm:shadow-none max-sm:[&>:has([data-slot$=dialog-footer])]:flex max-sm:[&>:has([data-slot$=dialog-footer])]:grow max-sm:[&>:has([data-slot$=dialog-footer])]:flex-col max-sm:[&>[data-slot$=dialog-header]]:sticky max-sm:[&>[data-slot$=dialog-header]]:top-0 max-sm:[&>[data-slot$=dialog-header]]:z-10 max-sm:[&>[data-slot$=dialog-header]]:bg-background max-sm:[&>[data-slot$=dialog-header]]:shadow-[0_-1.5rem_0_1.5rem_var(--background)]',
          props.class,
        )"
    >
      <slot />

      <DialogClose
        v-if="showCloseButton"
        data-slot="dialog-close"
        data-testid="dialog-close"
        :aria-label="$t('common.close')"
        :class="cn(buttonVariants({ variant: 'ghost', size: 'icon' }), 'absolute top-4 end-4', fullscreenOnMobile && 'max-sm:fixed max-sm:top-[calc(1rem+env(safe-area-inset-top))] max-sm:end-[calc(1rem+env(safe-area-inset-right))] max-sm:z-20')"
      >
        <IconX class="size-4" />
        <span class="sr-only">{{ $t('common.close') }}</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
