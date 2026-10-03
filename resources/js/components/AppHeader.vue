<script setup lang="ts">
import { SidebarTrigger } from '@/components/ui/sidebar';
</script>

<template>
    <header
        class="flex shrink-0 items-center justify-between gap-2 bg-card px-4 pt-4 md:px-8 md:pt-6"
    >
        <div class="flex h-12 min-w-0 flex-1 items-center gap-2">
            <SidebarTrigger
                class="-ms-1 md:hidden md:group-has-data-[collapsible=offcanvas]/sidebar-wrapper:inline-flex"
            />
            <slot name="left" />
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <slot name="right" />
        </div>
    </header>
</template>
