<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { IconLayoutSidebarLeftCollapse, IconLayoutSidebarLeftExpand, IconMenu2 } from "@tabler/icons-vue"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { isMobile, state, toggleSidebar } = useSidebar()
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn('h-9 w-9', props.class)"
    @click="toggleSidebar"
  >
    <IconMenu2 v-if="isMobile" />
    <IconLayoutSidebarLeftExpand v-else-if="state === 'collapsed'" />
    <IconLayoutSidebarLeftCollapse v-else />
    <span class="sr-only">Toggle Sidebar</span>
  </Button>
</template>
