<script setup lang="ts">
import { IconStarFilled } from '@tabler/icons-vue';

import { G2_REVIEWS_URL } from '@/lib/reviews';
</script>

<template>
    <a
        :href="G2_REVIEWS_URL"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex shrink-0 items-center gap-2 rounded-full border border-border bg-card px-3.5 py-1.5 text-sm font-medium text-foreground shadow-xs transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
        <span class="flex gap-0.5">
            <IconStarFilled
                v-for="star in 5"
                :key="star"
                class="size-3.5 text-amber-500"
            />
        </span>
        {{ $t('auth.reviews.eyebrow') }}
    </a>
</template>
