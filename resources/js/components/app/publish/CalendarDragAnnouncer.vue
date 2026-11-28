<script setup lang="ts">
defineProps<{
    text: string;
}>();
</script>

<template>
    <div
        class="sr-only"
        aria-live="polite"
        aria-atomic="true"
        data-testid="calendar-drag-announcer"
    >
        {{ text }}
    </div>
</template>
