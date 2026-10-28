<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import {
  IconAlertCircleFilled,
  IconAlertTriangleFilled,
  IconCircleCheckFilled,
  IconInfoCircleFilled,
  IconLoader2,
  IconX,
} from "@tabler/icons-vue"
import { Toaster as Sonner } from "vue-sonner"

const props = defineProps<ToasterProps>()
</script>

<template>
  <Sonner
    v-bind="props"
    position="top-center"
    offset="16px"
    close-button
    :style="{
      '--width': '480px',
      '--normal-bg': 'var(--popover)',
      '--normal-text': 'var(--popover-foreground)',
      '--normal-border': 'var(--border)',
      '--success-bg': 'var(--popover)',
      '--success-text': 'var(--popover-foreground)',
      '--success-border': 'var(--border)',
      '--error-bg': 'var(--popover)',
      '--error-text': 'var(--popover-foreground)',
      '--error-border': 'var(--border)',
      '--warning-bg': 'var(--popover)',
      '--warning-text': 'var(--popover-foreground)',
      '--warning-border': 'var(--border)',
      '--info-bg': 'var(--popover)',
      '--info-text': 'var(--popover-foreground)',
      '--info-border': 'var(--border)',
    }"
  >
    <template #success-icon>
      <IconCircleCheckFilled class="size-5" />
    </template>
    <template #error-icon>
      <IconAlertCircleFilled class="size-5" />
    </template>
    <template #warning-icon>
      <IconAlertTriangleFilled class="size-5" />
    </template>
    <template #info-icon>
      <IconInfoCircleFilled class="size-5" />
    </template>
    <template #loading-icon>
      <IconLoader2 class="size-5 animate-spin" />
    </template>
    <template #close-icon>
      <IconX class="size-4" />
    </template>
  </Sonner>
</template>

<style>
[data-sonner-toaster] [data-sonner-toast][data-styled="true"] {
  align-items: flex-start;
  gap: 8px;
  padding: 12px 16px 12px 12px;
  border: 0;
  border-radius: 12px;
  box-shadow:
    0 0 0 1px var(--border),
    0 0 1px 1px rgb(0 0 0 / 0.09),
    0 4px 8px -4px rgb(0 0 0 / 0.12),
    0 16px 24px -8px rgb(0 0 0 / 0.12);
  font-size: 14px;
  font-weight: 400;
  line-height: 21px;
}

[data-sonner-toaster] [data-sonner-toast] [data-icon] {
  width: 24px;
  height: 24px;
  margin: 0;
  justify-content: center;
}

[data-sonner-toaster] [data-sonner-toast] [data-icon] svg {
  width: 20px;
  height: 20px;
}

[data-sonner-toaster] [data-sonner-toast] [data-content] {
  flex: 1 1 auto;
  min-width: 0;
  padding-top: 1.5px;
}

[data-sonner-toaster] [data-sonner-toast][data-styled="true"] [data-close-button] {
  position: static;
  order: 99;
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--muted-foreground);
  transform: none;
}

[data-sonner-toaster] [data-sonner-toast][data-styled="true"] [data-close-button]:hover {
  background: var(--accent);
  color: var(--foreground);
}

[data-sonner-toaster] [data-sonner-toast] [data-description] {
  font-weight: 400;
  color: var(--muted-foreground);
}

@media (prefers-reduced-motion: no-preference) {
  [data-sonner-toaster] [data-sonner-toast] {
    transition:
      transform var(--motion-duration-toast-enter) var(--motion-easing-overlay-enter),
      opacity var(--motion-duration-toast-enter) var(--motion-easing-overlay-enter),
      height var(--motion-duration-toast-enter) var(--motion-easing-overlay-enter),
      box-shadow 200ms;
  }

  [data-sonner-toaster] [data-sonner-toast][data-removed="true"],
  [data-sonner-toaster] [data-sonner-toast][data-removed="true"][data-front="false"][data-swipe-out="false"][data-expanded="false"] {
    transition:
      transform var(--motion-duration-toast-exit) var(--motion-easing-overlay-exit),
      opacity var(--motion-duration-toast-exit) var(--motion-easing-overlay-exit),
      height var(--motion-duration-toast-exit) var(--motion-easing-overlay-exit);
  }
}

[data-sonner-toast][data-type="success"] [data-icon] {
  color: var(--success) !important;
}

[data-sonner-toast][data-type="error"] [data-icon] {
  color: var(--error) !important;
}

[data-sonner-toast][data-type="warning"] [data-icon] {
  color: var(--warning) !important;
}

[data-sonner-toast][data-type="info"] [data-icon] {
  color: var(--info) !important;
}
</style>
