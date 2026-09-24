<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    title?: string;
    total?: number | null;
    icon?: Component;
}>();
</script>

<template>
    <div class="flex min-w-0 items-center gap-3">
        <span
            v-if="icon"
            class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-border bg-background text-foreground shadow-xs"
            data-testid="header-icon"
            aria-hidden="true"
        >
            <component :is="icon" class="size-5" stroke-width="1.8" />
        </span>

        <div class="min-w-0">
            <slot>
                <h1
                    v-if="title"
                    class="truncate text-base font-semibold text-foreground"
                    data-testid="header-title"
                >
                    {{ title }}
                    <span
                        v-if="total != null && total > 0"
                        class="text-muted-foreground"
                    >
                        ({{ total }})
                    </span>
                </h1>
            </slot>
        </div>
    </div>
</template>
