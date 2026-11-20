<script setup lang="ts">
defineProps<{
    /** The post's place in the ranking, from 1. */
    rank: number;
}>();
</script>

<template>
    <span
        class="inline-flex h-6 min-w-7 shrink-0 items-center justify-center rounded-md bg-primary-selected px-1.5 text-xs font-semibold text-primary-text tabular-nums"
        >#{{ rank }}</span
    >
</template>
