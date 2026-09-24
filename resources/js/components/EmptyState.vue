<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    icon: Component;
    title: string;
    description: string;
}>();
</script>

<template>
    <div
        class="flex min-h-0 flex-1 flex-col items-center justify-center p-12 text-center"
        data-testid="empty-state"
    >
        <div
            class="mb-4 flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground"
        >
            <component :is="icon" class="size-6" stroke-width="1.75" />
        </div>
        <h3 class="mb-1 text-base font-semibold text-foreground">
            {{ title }}
        </h3>
        <p class="max-w-xs text-sm text-muted-foreground">{{ description }}</p>
        <div v-if="$slots.action" class="mt-6 flex gap-2">
            <slot name="action" />
        </div>
    </div>
</template>
