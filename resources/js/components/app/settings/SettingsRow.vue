<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6"
    >
        <div class="flex max-w-[440px] min-w-0 flex-col gap-2">
            <h2 class="text-base leading-5 font-emphasis text-foreground">
                {{ title }}
            </h2>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div class="flex shrink-0 items-center">
            <slot />
        </div>
    </div>
</template>
