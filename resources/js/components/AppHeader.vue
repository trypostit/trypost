<script setup lang="ts">
import { SidebarTrigger } from '@/components/ui/sidebar';
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-border bg-card px-4"
    >
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <SidebarTrigger class="-ml-1 md:hidden" />
            <slot name="left" />
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <slot name="right" />
        </div>
    </header>
</template>
