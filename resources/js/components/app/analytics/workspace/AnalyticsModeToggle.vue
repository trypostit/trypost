<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';

defineProps<{
    modelValue: string;
    label: string;
    options: readonly {
        mode: string;
        label: string;
        test: string;
        icon?: Component;
    }[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div
        class="inline-flex items-center gap-1 rounded-lg border border-border-strong p-1 max-sm:flex max-sm:w-full"
        role="group"
        :aria-label="trans(label)"
    >
        <button
            v-for="option in options"
            :key="option.mode"
            type="button"
            :data-testid="option.test"
            class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md px-2 text-sm font-medium whitespace-nowrap transition-control max-sm:flex-1 max-sm:justify-center max-sm:px-1.5 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :class="
                modelValue === option.mode
                    ? 'bg-primary-selected text-primary-text'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground'
            "
            :aria-pressed="modelValue === option.mode"
            @click="emit('update:modelValue', option.mode)"
        >
            <component
                :is="option.icon"
                v-if="option.icon"
                class="size-3.5 max-sm:hidden"
                aria-hidden="true"
            />
            {{ trans(option.label) }}
        </button>
    </div>
</template>
