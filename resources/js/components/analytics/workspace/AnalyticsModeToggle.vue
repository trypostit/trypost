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
        class="inline-flex rounded-lg border-2 border-foreground bg-card p-1 shadow-xs"
        role="group"
        :aria-label="trans(label)"
    >
        <button
            v-for="option in options"
            :key="option.mode"
            type="button"
            :data-testid="option.test"
            class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-foreground"
            :class="
                modelValue === option.mode
                    ? 'bg-violet-100 text-foreground'
                    : 'text-foreground/65 hover:bg-muted hover:text-foreground'
            "
            :aria-pressed="modelValue === option.mode"
            @click="emit('update:modelValue', option.mode)"
        >
            {{ trans(option.label) }}
        </button>
    </div>
</template>
