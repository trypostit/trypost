<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    title?: string;
    total?: number | null;
    icon?: Component;
}>();
</script>

<template>
    <div class="flex min-w-0 items-center gap-2">
        <span
            v-if="icon"
            class="flex size-[42px] shrink-0 items-center justify-center rounded-xl border border-border-strong bg-card text-foreground"
            data-testid="header-icon"
            aria-hidden="true"
        >
            <component :is="icon" class="size-6" stroke-width="1.5" />
        </span>

        <div class="min-w-0">
            <slot>
                <h1
                    v-if="title"
                    class="truncate font-heading text-xl leading-tight font-medium text-foreground"
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
