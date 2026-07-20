<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconStar, IconStarFilled } from '@tabler/icons-vue';
import { ref } from 'vue';

import { store as storeRating } from '@/routes/app/content-ratings';

const props = defineProps<{
    rateableType?: string;
    rateableId?: string;
}>();

const rating = ref(0);
const hovered = ref(0);
const submitted = ref(false);
const stars = ref<HTMLButtonElement[]>([]);

const http = useHttp<{
    rating: number;
    rateable_type: string | null;
    rateable_id: string | null;
}>({
    rating: 0,
    rateable_type: null,
    rateable_id: null,
});

const rate = async (value: number): Promise<void> => {
    if (submitted.value) {
        return;
    }

    rating.value = value;
    submitted.value = true;

    http.rating = value;
    http.rateable_type = props.rateableType ?? null;
    http.rateable_id = props.rateableId ?? null;

    try {
        await http.post(storeRating.url());
    } catch {
        // Failing here blocks nothing: reset so the user can rate again.
        submitted.value = false;
        rating.value = 0;
    }
};

// WAI-ARIA radiogroup pattern: arrows move focus and selection, Enter or click submits.
const focusStar = (value: number): void => {
    const clamped = Math.min(5, Math.max(1, value));
    rating.value = clamped;
    stars.value[clamped - 1]?.focus();
};

const onKeydown = (event: KeyboardEvent): void => {
    if (submitted.value) {
        return;
    }

    switch (event.key) {
        case 'ArrowRight':
        case 'ArrowUp':
            event.preventDefault();
            focusStar((rating.value || 0) + 1);
            break;
        case 'ArrowLeft':
        case 'ArrowDown':
            event.preventDefault();
            focusStar((rating.value || 1) - 1);
            break;
        case 'Home':
            event.preventDefault();
            focusStar(1);
            break;
        case 'End':
            event.preventDefault();
            focusStar(5);
            break;
    }
};

const isFilled = (star: number): boolean =>
    star <= (hovered.value || rating.value);

// Roving tabindex: um único ponto de entrada por Tab no grupo.
const tabindexFor = (star: number): number =>
    !submitted.value && star === (rating.value || 1) ? 0 : -1;
</script>

<template>
    <div class="flex items-center gap-2">
        <span class="text-xs font-medium text-muted-foreground">
            {{
                submitted
                    ? $t('posts.rating.thanks')
                    : $t('posts.rating.prompt')
            }}
        </span>
        <div
            class="flex items-center gap-0.5"
            role="radiogroup"
            :aria-label="$t('posts.rating.aria')"
            @mouseleave="hovered = 0"
            @keydown="onKeydown"
        >
            <button
                v-for="star in 5"
                :key="star"
                ref="stars"
                type="button"
                role="radio"
                :aria-checked="rating === star"
                :aria-label="String(star)"
                :tabindex="tabindexFor(star)"
                :disabled="submitted"
                class="cursor-pointer p-0.5 transition-colors disabled:cursor-default"
                :class="
                    isFilled(star)
                        ? 'text-primary'
                        : 'text-foreground/30 hover:text-primary'
                "
                @mouseenter="hovered = star"
                @click="rate(star)"
            >
                <component
                    :is="isFilled(star) ? IconStarFilled : IconStar"
                    class="size-5"
                    stroke-width="2"
                />
            </button>
        </div>
    </div>
</template>
