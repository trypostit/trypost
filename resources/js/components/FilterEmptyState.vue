<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    icon: Component;
    title: string;
    description?: string;
    testId: string;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 px-2 pt-4 pb-3 text-center"
        :data-testid="testId"
    >
        <span
            class="flex size-12 items-center justify-center rounded-full bg-primary-subtle text-primary-text"
            aria-hidden="true"
        >
            <component :is="icon" class="size-6" />
        </span>
        <div class="flex flex-col gap-1">
            <p class="text-sm font-emphasis text-foreground">{{ title }}</p>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <slot />
    </div>
</template>
