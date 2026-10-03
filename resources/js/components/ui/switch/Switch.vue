<script setup lang="ts">
import type { SwitchRootEmits, SwitchRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { IconCheck, IconX } from "@tabler/icons-vue"
import { reactiveOmit } from "@vueuse/core"
import {
  SwitchRoot,
  SwitchThumb,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

const props = withDefaults(
  defineProps<SwitchRootProps & { class?: HTMLAttributes["class"], size?: "default" | "sm" }>(),
  { size: "default" },
)

const emits = defineEmits<SwitchRootEmits>()

const delegatedProps = reactiveOmit(props, "class", "size")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <SwitchRoot
    v-slot="slotProps"
    data-slot="switch"
    :data-size="size"
    v-bind="forwarded"
    :class="cn(
      'peer relative data-[state=checked]:bg-primary-strong data-[state=unchecked]:bg-subtle-foreground inline-flex shrink-0 cursor-pointer items-center rounded-full transition-control outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50',
      size === 'sm' ? 'h-4 w-[30px] p-0.5' : 'h-6 w-[43px] p-[3px]',
      props.class,
    )"
  >
    <template v-if="size === 'sm'">
      <IconCheck
        aria-hidden="true"
        stroke-width="2.2"
        :class="cn('pointer-events-none absolute start-[3px] size-2.5 text-white transition-opacity duration-(--motion-duration-control-feedback) ease-(--motion-easing-control-feedback)', slotProps.modelValue ? 'opacity-100' : 'opacity-0')"
      />
      <IconX
        aria-hidden="true"
        stroke-width="2.2"
        :class="cn('pointer-events-none absolute end-[3px] size-2.5 text-white transition-opacity duration-(--motion-duration-control-feedback) ease-(--motion-easing-control-feedback)', slotProps.modelValue ? 'opacity-0' : 'opacity-100')"
      />
    </template>
    <SwitchThumb
      data-slot="switch-thumb"
      :class="cn(
        'bg-white pointer-events-none relative block rounded-full ring-0 transition-transform duration-(--motion-duration-control-feedback) ease-(--motion-easing-control-feedback) data-[state=unchecked]:translate-x-0',
        size === 'sm' ? 'size-3 data-[state=checked]:translate-x-[14px] rtl:data-[state=checked]:-translate-x-[14px]' : 'size-4.5 data-[state=checked]:translate-x-[19px] rtl:data-[state=checked]:-translate-x-[19px]',
      )"
    >
      <slot name="thumb" v-bind="slotProps" />
    </SwitchThumb>
  </SwitchRoot>
</template>
