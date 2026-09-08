<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconStarFilled } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import AuthLanguageSwitcher from '@/components/auth/AuthLanguageSwitcher.vue';
import type { Auth } from '@/types';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();

const isGuest = computed(() => !(page.props.auth as Auth).user);

const g2ReviewsUrl = 'https://www.g2.com/products/trypost/reviews';

const reviewKeys = ['paulo_dantas', 'diego', 'luiz', 'pedro', 'paulo_castellano'] as const;

const reviewPeople = {
    paulo_dantas: { name: 'Paulo Dantas', photo: '/images/reviews/paulo-dantas.jpg' },
    diego: { name: 'Diego Sampaio', photo: '/images/reviews/diego-sampaio.jpg' },
    luiz: { name: 'Luiz Mazini', photo: '/images/reviews/luiz-mazini.jpg' },
    pedro: { name: 'Pedro Campos', photo: '/images/reviews/pedro-campos.jpg' },
    paulo_castellano: { name: 'Paulo Castellano', photo: '/images/reviews/paulo-castellano.jpg' },
};

const reviews = computed(() =>
    reviewKeys.map((key, index) => ({
        key,
        name: reviewPeople[key].name,
        photo: reviewPeople[key].photo,
        role: trans(`auth.reviews.${key}.role`),
        quote: trans(`auth.reviews.${key}.quote`),
        rotation: index % 2 === 0 ? '-rotate-1' : 'rotate-1',
    })),
);

// The marquee scrolls one full copy of the list, so a second copy fills the gap
// it leaves behind. Both copies must render identically for the loop to be
// seamless, hence the rotation living on the review rather than on the index.
const loopedReviews = computed(() => [
    ...reviews.value.map((review) => ({ ...review, duplicate: false })),
    ...reviews.value.map((review) => ({ ...review, duplicate: true })),
]);
</script>

<template>
    <div class="grid min-h-svh grid-cols-1 lg:grid-cols-2">
        <div class="flex min-w-0 flex-col gap-4 p-6 md:p-10">
            <div class="flex items-start justify-between gap-4">
                <img
                    src="/images/trypost/logo-light.png"
                    alt="TryPost"
                    class="h-7"
                />

                <AuthLanguageSwitcher v-if="isGuest" />
            </div>

            <div class="flex flex-1 items-center justify-center">
                <div class="w-full max-w-lg">
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col items-center gap-2 text-center">
                            <h1 v-if="title" class="text-2xl font-bold">{{ title }}</h1>
                            <p v-if="description" class="text-sm text-balance text-muted-foreground">
                                {{ description }}
                            </p>
                        </div>

                        <slot />
                    </div>
                </div>
            </div>
        </div>

        <div
            class="relative hidden overflow-hidden border-l-2 border-foreground bg-accent lg:sticky lg:top-0 lg:block lg:h-svh lg:self-start"
        >
            <!-- Soft violet glow blobs for ambient depth (off-canvas). -->
            <div class="pointer-events-none absolute -top-24 -right-24 size-[440px] rounded-full bg-violet-200/50 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-32 -left-32 size-[440px] rounded-full bg-fuchsia-200/40 blur-3xl" />

            <!-- Dot pattern overlay (subtle). -->
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.06]"
                style="background-image: radial-gradient(circle, #0a0a0a 1px, transparent 1px); background-size: 28px 28px;"
            />

            <div class="relative flex h-full flex-col px-12 pt-14 xl:px-16">
                <div class="mx-auto w-full max-w-md">
                    <a
                        :href="g2ReviewsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="group inline-flex items-center gap-3"
                        data-testid="auth-reviews-g2-link"
                    >
                        <div class="flex gap-0.5">
                            <IconStarFilled v-for="star in 5" :key="star" class="size-4 text-amber-500" />
                        </div>
                        <span class="text-xs font-bold tracking-widest text-foreground/70 uppercase underline-offset-4 transition-colors group-hover:text-foreground group-hover:underline">
                            {{ $t('auth.reviews.eyebrow') }}
                        </span>
                    </a>

                    <h2 class="h3 mt-4 whitespace-nowrap text-foreground">
                        {{ $t('auth.reviews.heading') }}
                    </h2>
                </div>

                <div class="relative mt-10 min-h-0 flex-1 overflow-hidden [mask-image:linear-gradient(to_bottom,transparent,black_12%,black_82%,transparent)]">
                    <div class="marquee mx-auto flex w-full max-w-md flex-col gap-4" data-testid="auth-reviews">
                        <figure
                            v-for="(review, index) in loopedReviews"
                            :key="`${review.key}-${index}`"
                            class="shrink-0 rounded-xl border-2 border-foreground bg-card p-5 shadow-sm"
                            :class="review.rotation"
                            :aria-hidden="review.duplicate"
                        >
                            <div class="flex gap-0.5">
                                <IconStarFilled v-for="star in 5" :key="star" class="size-3.5 text-amber-500" />
                            </div>

                            <blockquote class="mt-3 text-sm leading-relaxed text-foreground/80">
                                “{{ review.quote }}”
                            </blockquote>

                            <figcaption class="mt-4 flex items-center gap-3">
                                <img
                                    :src="review.photo"
                                    :alt="review.name"
                                    width="96"
                                    height="96"
                                    loading="lazy"
                                    class="size-10 shrink-0 rounded-full border-2 border-foreground object-cover shadow-2xs"
                                />
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-bold text-foreground">{{ review.name }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">{{ review.role }}</span>
                                </span>
                            </figcaption>
                        </figure>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes marquee-down {
    0% {
        transform: translateY(calc(-50% - 0.5rem));
    }

    100% {
        transform: translateY(0);
    }
}

.marquee {
    animation: marquee-down 45s linear infinite;
}

.marquee:hover {
    animation-play-state: paused;
}

@media (prefers-reduced-motion: reduce) {
    .marquee {
        animation: none;
    }
}
</style>
