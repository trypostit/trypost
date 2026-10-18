<script setup lang="ts">
import { IconStar, IconStarFilled } from '@tabler/icons-vue';

defineProps<{
    rating: number;
}>();

const STARS = [1, 2, 3, 4, 5];
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-0.5 text-[#F2B90F]"
        role="img"
        :aria-label="$t('inbox.review.rating', { rating: String(rating) })"
        :data-rating="rating"
        data-testid="inbox-review-stars"
    >
        <template v-for="star in STARS" :key="star">
            <IconStarFilled v-if="star <= rating" class="size-3.5" />
            <IconStar v-else class="size-3.5 text-muted-foreground" />
        </template>
    </span>
</template>
