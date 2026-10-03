<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <section class="flex flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 class="text-base leading-5 font-emphasis text-foreground">
                    {{ title }}
                </h2>
                <p v-if="description" class="mt-2 text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <div
                v-if="$slots['actions']"
                class="flex shrink-0 items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </div>
        <slot />
    </section>
</template>
