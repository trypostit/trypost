<script setup lang="ts">
/** What a backoffice list shows when it has nothing to list. */
import { computed } from 'vue';

type EmptyStateVariant = 'page' | 'card' | 'section' | 'chart' | 'inset';

const VARIANT_CLASSES: Record<EmptyStateVariant, string> = {
    page: 'rounded-xl border border-dashed border-border px-4 py-12',
    card: 'rounded-xl border border-dashed border-border px-4 py-8',
    section: 'border-t border-border px-4 py-6 sm:px-5',
    chart: 'py-16',
    inset: 'px-4 py-4',
};

const props = withDefaults(
    defineProps<{
        /**
         * `page` stands for a whole list and `card` for a settings section, both
         * a dashed box; `section` is a row under the title of a flush `AnalyticsSection`;
         * `chart` takes the height of the chart it stands for; `inset` is a row inside
         * a box that already frames it.
         */
        variant?: EmptyStateVariant;
        testId?: string;
    }>(),
    { variant: 'card' },
);

const variantClass = computed((): string => VARIANT_CLASSES[props.variant]);
</script>

<template>
    <p class="text-center text-sm text-muted-foreground" :class="variantClass" :data-testid="testId">
        <slot />
    </p>
</template>
