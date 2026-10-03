<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconCircleCheck, IconStarFilled } from '@tabler/icons-vue';
import { computed } from 'vue';

import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { Auth } from '@/types';

const widthClass = {
    sm: 'max-w-[360px]',
    md: 'max-w-xl',
} as const;

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        status?: string;
        panel?: boolean;
        width?: keyof typeof widthClass;
    }>(),
    {
        title: undefined,
        description: undefined,
        status: undefined,
        panel: false,
        width: 'sm',
    },
);

const page = usePage();

const isGuest = computed(() => !(page.props.auth as Auth).user);

const g2ReviewsUrl = 'https://www.g2.com/products/trypost/reviews';

const reviews = [
    {
        key: 'paulo_dantas',
        name: 'Paulo Dantas',
        photo: '/images/reviews/paulo-dantas.jpg',
    },
    {
        key: 'diego',
        name: 'Diego Sampaio',
        photo: '/images/reviews/diego-sampaio.jpg',
    },
    {
        key: 'luiz',
        name: 'Luiz Mazini',
        photo: '/images/reviews/luiz-mazini.jpg',
    },
    {
        key: 'pedro',
        name: 'Pedro Campos',
        photo: '/images/reviews/pedro-campos.jpg',
    },
    {
        key: 'paulo_castellano',
        name: 'Paulo Castellano',
        photo: '/images/reviews/paulo-castellano.jpg',
    },
].map((review, index) => ({
    ...review,
    rotation: index % 2 === 0 ? '-rotate-1' : 'rotate-1',
}));

// The marquee scrolls one full copy of the list, so a second copy fills the gap
// it leaves behind. Both copies must render identically for the loop to be
// seamless, hence the rotation living on the review rather than on the index.
const loopedReviews = [
    ...reviews.map((review) => ({ ...review, duplicate: false })),
    ...reviews.map((review) => ({ ...review, duplicate: true })),
];
</script>

<template>
    <div
        :class="[
            'grid min-h-svh grid-cols-1 bg-muted',
            panel ? 'lg:grid-cols-2' : '',
        ]"
    >
        <div
            class="relative flex min-w-0 flex-col items-center px-4 pt-13 pb-8 sm:justify-center sm:px-8 sm:py-16"
        >
            <div class="absolute end-4 top-4 sm:end-6 sm:top-6">
                <LocaleSwitcher v-if="isGuest" />
            </div>

            <div :class="['flex w-full flex-col gap-6', widthClass[width]]">
                <div class="flex flex-col items-center gap-4 text-center">
                    <img
                        src="/images/trypost/icon.png"
                        alt="TryPost"
                        class="motion-auth-logo h-11 w-auto"
                        data-testid="auth-logo"
                    />
                    <div
                        v-if="title || description"
                        class="flex flex-col gap-1"
                    >
                        <h1
                            v-if="title"
                            class="font-heading text-xl font-medium tracking-tight text-balance text-foreground"
                            data-testid="auth-title"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm text-balance text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                </div>

                <Alert
                    v-if="status"
                    class="[&>svg]:text-success-text"
                    data-testid="auth-status"
                >
                    <IconCircleCheck />
                    <AlertDescription>{{ status }}</AlertDescription>
                </Alert>

                <slot />
            </div>
        </div>

        <div
            v-if="panel"
            class="hidden p-8 ps-0 lg:sticky lg:top-0 lg:block lg:h-svh lg:self-start"
        >
            <div
                class="relative flex h-full flex-col items-center overflow-hidden rounded-[20px] bg-primary-subtle px-12 pt-14 text-center xl:px-16"
            >
                <a
                    :href="g2ReviewsUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="group inline-flex items-center gap-2 rounded-md bg-primary-text px-3 py-1.5 text-xs font-medium tracking-[0.04em] text-primary-strong-foreground uppercase transition-control dark:text-background hover:bg-primary-text-hover"
                    data-testid="auth-reviews-g2-link"
                >
                    <span class="flex gap-0.5">
                        <IconStarFilled
                            v-for="star in 5"
                            :key="star"
                            class="size-3 text-amber-300"
                        />
                    </span>
                    {{ $t('auth.reviews.eyebrow') }}
                </a>

                <h2
                    class="motion-auth-reveal mt-6 max-w-lg font-heading text-[clamp(30px,3.2vw,48px)] leading-[1.12] font-normal tracking-[-0.03em] text-balance text-foreground"
                >
                    {{ $t('auth.reviews.heading') }}
                </h2>

                <div
                    class="motion-auth-fade relative mt-10 min-h-0 w-full flex-1 overflow-hidden [mask-image:linear-gradient(to_bottom,transparent,black_12%,black_82%,transparent)]"
                >
                    <div
                        class="marquee mx-auto flex w-full max-w-md flex-col gap-4 text-start"
                        data-testid="auth-reviews"
                    >
                        <figure
                            v-for="(review, index) in loopedReviews"
                            :key="`${review.key}-${index}`"
                            class="shrink-0 rounded-xl border border-border bg-card p-5"
                            :class="review.rotation"
                            :aria-hidden="review.duplicate"
                        >
                            <div class="flex gap-0.5">
                                <IconStarFilled
                                    v-for="star in 5"
                                    :key="star"
                                    class="size-3.5 text-amber-500"
                                />
                            </div>

                            <blockquote
                                class="mt-3 text-sm text-foreground"
                            >
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
                                        >{{
                                            $t(`auth.reviews.${review.key}.role`)
                                        }}</span
                                    >
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
