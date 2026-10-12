<script setup lang="ts">
import { IconStarFilled } from '@tabler/icons-vue';

import type { Review } from '@/lib/reviews';

defineProps<{
    review: Review;
}>();
</script>

<template>
    <figure class="shrink-0 rounded-xl border border-border bg-card p-5">
        <div
            class="flex gap-0.5"
            role="img"
            :aria-label="$t('auth.reviews.eyebrow')"
        >
            <IconStarFilled
                v-for="star in 5"
                :key="star"
                class="size-3.5 text-rating"
            />
        </div>

        <blockquote class="mt-3 text-sm text-foreground">
            “{{ $t(`auth.reviews.${review.key}.quote`) }}”
        </blockquote>

        <figcaption class="mt-4 flex items-center gap-3">
            <img
                :src="review.photo"
                :alt="review.name"
                width="96"
                height="96"
                loading="lazy"
                class="size-10 shrink-0 rounded-lg object-cover"
            />
            <span class="min-w-0">
                <span
                    class="block truncate text-sm leading-tight font-emphasis text-foreground"
                    >{{ review.name }}</span
                >
                <span
                    class="mt-1 block truncate text-sm text-muted-foreground"
                    >{{ $t(`auth.reviews.${review.key}.role`) }}</span
                >
            </span>
        </figcaption>
    </figure>
</template>
