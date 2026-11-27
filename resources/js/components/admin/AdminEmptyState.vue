<script setup lang="ts">
/** The dashed box a backoffice list shows when it has nothing to list. */
import { computed } from 'vue';

type EmptyStateVariant = 'page' | 'card' | 'section';

const VARIANT_CLASSES: Record<EmptyStateVariant, string> = {
    page: 'rounded-xl border border-dashed border-border px-4 py-12',
    card: 'rounded-xl border border-dashed border-border px-4 py-8',
    section:
        'mx-4 mb-4 rounded-lg border border-dashed border-border-strong px-5 py-10 sm:mx-5 sm:mb-5',
};

const props = withDefaults(
    defineProps<{
        /** `page` stands for a whole list, `card` for a settings section, `section` sits inside an `AnalyticsSection`. */
        variant?: EmptyStateVariant;
        testId?: string;
    }>(),
    { variant: 'card', testId: undefined },
);

const variantClass = computed((): string => VARIANT_CLASSES[props.variant]);
</script>

<template>
    <p class="text-center text-sm text-muted-foreground" :class="variantClass" :data-testid="testId">
        <slot />
    </p>
</template>
