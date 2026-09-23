<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
    >
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h2 class="text-base font-semibold text-foreground">
                    {{ title }}
                </h2>
                <p v-if="subtitle" class="text-xs text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </section>
</template>
