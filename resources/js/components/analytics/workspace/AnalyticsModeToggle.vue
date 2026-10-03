<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';

defineProps<{
    modelValue: string;
    label: string;
    options: readonly { mode: string; label: string; test: string }[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div
        class="inline-flex h-8 items-center gap-1 rounded-lg border border-border-strong bg-card p-[3px]"
        role="group"
        :aria-label="trans(label)"
    >
        <button
            v-for="option in options"
            :key="option.mode"
            type="button"
            :data-testid="option.test"
            class="inline-flex h-6 items-center rounded-md px-2 text-sm font-medium whitespace-nowrap transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :class="
                modelValue === option.mode
                    ? 'bg-primary-selected text-primary-text'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground'
            "
            :aria-pressed="modelValue === option.mode"
            @click="emit('update:modelValue', option.mode)"
        >
            {{ trans(option.label) }}
        </button>
    </div>
</template>
