<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        as?: string;
        icon?: Component;
        interactive?: boolean;
    }>(),
    {
        as: 'li',
        icon: undefined,
        interactive: false,
    },
);
</script>

<template>
    <component
        :is="as"
        class="flex items-center gap-3 rounded-xl border border-border bg-card p-4"
        :class="{
            'cursor-pointer transition-control hover:bg-muted': interactive,
        }"
    >
        <span
            v-if="icon"
            class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
        >
            <component :is="icon" class="size-5" />
        </span>
        <slot name="media" />
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <slot />
        </div>
        <slot name="actions" />
    </component>
</template>
