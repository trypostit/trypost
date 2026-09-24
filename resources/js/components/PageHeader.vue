<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
    total?: number;
}>();
</script>

<template>
    <header class="space-y-1">
        <h1
            class="text-xl leading-tight font-semibold text-foreground sm:text-2xl"
        >
            {{ title }}
            <span
                v-if="total != null && total > 0"
                class="text-muted-foreground"
                >({{ total }})</span
            >
        </h1>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
