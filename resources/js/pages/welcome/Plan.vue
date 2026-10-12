<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconBuilding,
    IconGift,
    IconInfoCircle,
    IconLock,
} from '@tabler/icons-vue';

import PlanFeatureList from '@/components/billing/PlanFeatureList.vue';
import G2ReviewsLink from '@/components/reviews/G2ReviewsLink.vue';
import ReviewCard from '@/components/reviews/ReviewCard.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { REVIEWS } from '@/lib/reviews';
import { store } from '@/routes/app/welcome/plan';
import type { PlanOption } from '@/types/plan';

defineProps<{
    plans: PlanOption[];
}>();

const form = useForm({});

// The marquee scrolls one full copy of the reviews; the second copy fills the
// gap it leaves behind so the loop is seamless.
const loopedReviews = [
    ...REVIEWS.map((review) => ({ ...review, duplicate: false })),
    ...REVIEWS.map((review) => ({ ...review, duplicate: true })),
];

const submit = (): void => {
    if (form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.plan_title')" />

    <WelcomeLayout
        :title="$t('welcome.plan_title')"
        :description="$t('welcome.plan_description')"
        step="plan"
    >
        <article
            v-for="plan in plans"
            :key="plan.id"
            class="grid overflow-hidden rounded-xl border border-border bg-card text-start sm:grid-cols-[2fr_3fr]"
            :data-testid="`plan-card-${plan.slug}`"
        >
            <div
                class="flex flex-col items-start justify-between gap-8 bg-primary-subtle p-5 sm:p-6 dark:bg-primary-selected"
            >
                <span
                    class="inline-flex size-10 items-center justify-center rounded-lg bg-card"
                    aria-hidden="true"
                >
                    <IconGift class="size-5 text-primary-text" />
                </span>

                <p class="flex flex-col gap-1">
                    <span
                        class="text-5xl leading-none font-strong tabular-nums"
                        :data-testid="`plan-price-first-month-${plan.slug}`"
                        >{{ $t('billing.subscribe.prices.first_month') }}</span
                    >
                    <span
                        class="text-base text-foreground"
                        :data-testid="`plan-price-suffix-${plan.slug}`"
                        >{{ $t('welcome.plan_first_month') }}</span
                    >
                </p>
            </div>

            <PlanFeatureList
                :scope="plan.slug"
                class="p-5 sm:p-6"
            >
                <template #after>
                    <li
                        class="flex items-start gap-2 text-sm leading-5 text-foreground"
                        :data-testid="`plan-highlight-${plan.slug}`"
                    >
                        <IconBuilding
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="inline-flex items-start gap-1.5">
                            {{ $t('billing.plans.workspaces_one') }}
                            <TooltipProvider :delay-duration="200">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <button
                                            type="button"
                                            class="mt-0.5 inline-flex size-4 shrink-0 cursor-pointer items-center justify-center text-muted-foreground transition-control hover:text-foreground"
                                            :aria-label="
                                                $t(
                                                    'billing.plans.workspaces_tooltip',
                                                )
                                            "
                                            :data-testid="`plan-workspaces-info-${plan.slug}`"
                                        >
                                            <IconInfoCircle class="size-4" />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        side="top"
                                        :side-offset="8"
                                        class="max-w-56 text-start"
                                    >
                                        {{
                                            $t(
                                                'billing.plans.workspaces_tooltip',
                                            )
                                        }}
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </span>
                    </li>
                </template>
            </PlanFeatureList>
        </article>

        <section
            class="mt-8 flex flex-col items-center gap-4"
            data-testid="welcome-plan-reviews"
        >
            <G2ReviewsLink />

            <div
                class="w-full overflow-hidden [mask-image:linear-gradient(to_right,transparent,black_8%,black_92%,transparent)]"
            >
                <div class="reviews-marquee flex w-max items-start gap-4">
                    <ReviewCard
                        v-for="(review, index) in loopedReviews"
                        :key="`${review.key}-${index}`"
                        :review="review"
                        class="w-72"
                        :aria-hidden="review.duplicate"
                    />
                </div>
            </div>
        </section>

        <template #actions>
            <WelcomeContinueButton
                :disabled="form.processing"
                :label="
                    $t('billing.plans.start_first_month', {
                        price: $t('billing.subscribe.prices.first_month'),
                    })
                "
                testid="welcome-plan-continue"
                @continue="submit"
            />
            <p
                class="text-center text-sm text-muted-foreground"
                data-testid="welcome-plan-terms"
            >
                {{
                    $t('welcome.plan_terms', {
                        price: $t('billing.subscribe.prices.socials.monthly'),
                    })
                }}
            </p>
            <p
                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                data-testid="welcome-plan-secure-checkout"
            >
                <IconLock class="size-3.5 shrink-0" />
                {{ $t('welcome.plan_secure_checkout') }}
            </p>
        </template>
    </WelcomeLayout>
</template>

<style scoped>
@keyframes reviews-marquee {
    0% {
        transform: translateX(0);
    }

    100% {
        transform: translateX(calc(-50% - 0.5rem));
    }
}

@keyframes reviews-marquee-rtl {
    0% {
        transform: translateX(0);
    }

    100% {
        transform: translateX(calc(50% + 0.5rem));
    }
}

.reviews-marquee {
    animation: reviews-marquee 60s linear infinite;
}

.reviews-marquee:dir(rtl) {
    animation-name: reviews-marquee-rtl;
}

.reviews-marquee:hover {
    animation-play-state: paused;
}

@media (prefers-reduced-motion: reduce) {
    .reviews-marquee {
        animation: none;
    }
}
</style>
