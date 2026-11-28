<script setup lang="ts">
withDefaults(
    defineProps<{
        /** `full` is the mark and the "trypost.it" wordmark, `mark` the round mark alone. */
        variant?: 'full' | 'mark';
    }>(),
    { variant: 'full' },
);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-[0.32em] font-logo leading-none font-bold tracking-[-0.01em] text-foreground"
        role="img"
        aria-label="TryPost"
        data-testid="app-logo"
    >
        <svg
            viewBox="0 0 410 410"
            class="size-[1.15em] shrink-0 [clip-path:circle(50%)]"
            aria-hidden="true"
        >
            <rect width="410" height="410" class="fill-white dark:fill-[#040000]" />
            <path d="M410 109.736V410H128.863L410 109.736Z" fill="#3660E0" />
            <path d="M410 0H281.138L205 81.3178L410 300.264V0Z" fill="#855ED4" />
            <path d="M0 0V300.264L205 81.3178L281.138 0H0Z" fill="#FFCC33" />
            <path
                d="M0 109.736V410H128.862L205 328.682L0 109.736Z"
                fill="#F39200"
            />
        </svg>
        <span v-if="variant === 'full'" aria-hidden="true">trypost.it</span>
    </span>
</template>
