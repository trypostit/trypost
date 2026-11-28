<script setup lang="ts">
defineProps<{
    exceeded?: boolean;
}>();
</script>

<template>
    <span
        class="text-xs tabular-nums"
        :class="
            exceeded
                ? 'font-medium text-destructive-text'
                : 'text-muted-foreground'
        "
        :data-exceeded="exceeded ? '' : undefined"
    >
        <slot />
    </span>
</template>
