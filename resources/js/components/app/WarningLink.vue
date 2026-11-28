<script setup lang="ts">
/** The action inside a `WarningLine`, drawn as an underlined link at the end of its sentence. */
withDefaults(defineProps<{ disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ click: [] }>();

const click = (): void => emit('click');
</script>

<template>
    <button
        type="button"
        class="font-medium underline underline-offset-2 hover:text-foreground/80 disabled:pointer-events-none disabled:opacity-50"
        :disabled="disabled"
        @click="click"
    >
        <slot />
    </button>
</template>
