<script setup lang="ts">
import { IconStarFilled } from '@tabler/icons-vue';

import type { Review } from '@/lib/reviews';

defineProps<{
    review: Review;
}>();
</script>

<template>
    <figure class="flex flex-col gap-2">
        <span class="flex gap-0.5">
            <IconStarFilled
                v-for="star in 5"
                :key="star"
                class="size-3.5 text-amber-500 dark:text-yellow-400"
            />
        </span>
        <blockquote class="text-sm text-foreground">
            “{{ $t(`auth.reviews.${review.key}.quote`) }}”
        </blockquote>
        <figcaption class="text-xs text-muted-foreground">
            <span class="font-medium text-foreground">{{ review.name }}</span>
            <span class="block">{{
                $t(`auth.reviews.${review.key}.role`)
            }}</span>
        </figcaption>
    </figure>
</template>
