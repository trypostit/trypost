<script setup lang="ts">
defineProps<{
    body: string;
}>();
</script>

<template>
    <p
        class="mt-0.5 text-sm break-words whitespace-pre-wrap"
        data-testid="note-body"
        v-text="body"
    />
</template>
