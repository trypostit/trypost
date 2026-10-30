<script setup lang="ts">
interface Label {
    id: string;
    name: string;
    color: string;
}

defineProps<{
    label: Label;
}>();
</script>

<template>
    <span
        class="inline-flex h-6 max-w-full min-w-0 items-center gap-1.5 rounded-md border border-border-strong bg-card px-2 text-xs font-medium text-foreground"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            :style="{ backgroundColor: label.color }"
            aria-hidden="true"
        />
        <span class="truncate">{{ label.name }}</span>
    </span>
</template>
