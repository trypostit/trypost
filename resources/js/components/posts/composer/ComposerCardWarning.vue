<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';
</script>

<template>
    <div
        role="status"
        class="flex items-start gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
    >
        <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
        <span><slot /></span>
    </div>
</template>
