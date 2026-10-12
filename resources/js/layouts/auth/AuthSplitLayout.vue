<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconCircleCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import LanguageSelect from '@/components/LanguageSelect.vue';
import G2ReviewsLink from '@/components/reviews/G2ReviewsLink.vue';
import ReviewCard from '@/components/reviews/ReviewCard.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useGuestLocale } from '@/composables/useGuestLocale';
import { REVIEWS } from '@/lib/reviews';
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
const { locale, languages } = useGuestLocale();

const reviews = REVIEWS.map((review, index) => ({
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
            class="relative flex min-h-svh min-w-0 flex-col items-center px-4 pt-16 pb-8 sm:px-8 sm:py-16"
        >
            <div class="absolute end-4 top-4 sm:end-6 sm:top-6">
                <LanguageSelect
                    v-if="isGuest"
                    v-model="locale"
                    :languages="languages"
                    :label="$t('settings.preferences.language.heading')"
                    testid="language-picker"
                    trigger-class="bg-card"
                />
            </div>

            <div
                :class="['my-auto flex w-full flex-col gap-6', widthClass[width]]"
                data-testid="auth-content"
            >
                <div class="flex flex-col items-center gap-4 text-center">
                    <AppLogo
                        variant="mark"
                        class="motion-auth-logo text-[38px]"
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

            <div
                v-if="$slots.footer"
                class="w-full max-w-sm pt-8 sm:absolute sm:inset-x-0 sm:bottom-6 sm:mx-auto sm:pt-0"
                data-testid="auth-footer"
            >
                <slot name="footer" />
            </div>
        </div>

        <div
            v-if="panel"
            class="hidden p-8 ps-0 lg:sticky lg:top-0 lg:block lg:h-svh lg:self-start"
        >
            <div
                class="relative flex h-full flex-col items-center overflow-hidden rounded-[20px] bg-primary-subtle px-12 pt-14 text-center xl:px-16"
            >

                <h2
                    class="motion-auth-reveal max-w-lg font-heading text-[clamp(30px,3.2vw,48px)] leading-[1.12] font-normal tracking-[-0.03em] text-balance text-foreground"
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
                        <ReviewCard
                            v-for="(review, index) in loopedReviews"
                            :key="`${review.key}-${index}`"
                            :review="review"
                            :class="review.rotation"
                            :aria-hidden="review.duplicate"
                        />
                    </div>
                </div>
                <G2ReviewsLink
                    class="motion-auth-fade my-6"
                    data-testid="auth-reviews-g2-link"
                />
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
