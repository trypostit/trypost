<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <section
        class="rounded-xl border-2 border-foreground bg-card p-5 shadow-sm sm:p-6"
    >
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <h2
                    class="text-2xl leading-tight font-semibold text-foreground"
                    style="font-family: var(--font-display)"
                >
                    {{ title }}
                </h2>
                <p v-if="subtitle" class="text-sm text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </section>
</template>
