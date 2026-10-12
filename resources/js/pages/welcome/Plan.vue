<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconBuilding,
    IconStarFilled,
} from '@tabler/icons-vue';

import PlanFeatureInfo from '@/components/billing/PlanFeatureInfo.vue';
import PlanFeatureList from '@/components/billing/PlanFeatureList.vue';
import ReviewQuote from '@/components/reviews/ReviewQuote.vue';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import { useAutoRotate } from '@/composables/useAutoRotate';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { REVIEWS } from '@/lib/reviews';
import { store } from '@/routes/app/welcome/plan';
import type { PlanOption } from '@/types/plan';

defineProps<{
    plans: PlanOption[];
}>();

const form = useForm({});

const {
    active: activeReview,
    select: selectReview,
    pause: pauseReviews,
    resume: resumeReviews,
} = useAutoRotate(REVIEWS.length, 6000);

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
            class="grid overflow-hidden rounded-xl text-start sm:grid-cols-[2fr_3fr]"
            :data-testid="`plan-card-${plan.slug}`"
        >
            <div
                class="flex flex-row-reverse items-center justify-between gap-4 bg-primary-subtle p-5 sm:flex-col sm:items-start sm:gap-8 sm:p-6 dark:bg-primary-selected"
            >
                <div
                    class="flex flex-col items-end gap-2 sm:w-full sm:items-stretch sm:gap-6"
                    data-testid="welcome-plan-reviews"
                    @mouseenter="pauseReviews"
                    @mouseleave="resumeReviews"
                >
                    <div class="flex -space-x-2 rtl:space-x-reverse">
                        <button
                            v-for="(review, index) in REVIEWS"
                            :key="review.key"
                            type="button"
                            :aria-label="review.name"
                            :aria-pressed="index === activeReview"
                            :class="[
                                'relative cursor-pointer rounded-full transition-transform duration-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                index === activeReview
                                    ? 'z-10 scale-115 shadow-md'
                                    : 'hover:z-10',
                            ]"
                            :data-testid="`welcome-plan-reviewer-${review.key}`"
                            @click="selectReview(index)"
                        >
                            <img
                                :src="review.photo"
                                alt=""
                                width="72"
                                height="72"
                                :class="[
                                    'size-9 rounded-full border-2 border-card object-cover',
                                ]"
                            />
                        </button>
                    </div>

                    <span
                        class="flex gap-0.5 sm:hidden"
                        role="img"
                        :aria-label="$t('auth.reviews.eyebrow')"
                    >
                        <IconStarFilled
                            v-for="star in 5"
                            :key="star"
                            class="size-3.5 text-rating"
                        />
                    </span>

                    <div class="grid max-sm:hidden">
                        <ReviewQuote
                            v-for="review in REVIEWS"
                            :key="review.key"
                            :review="review"
                            class="invisible [grid-area:1/1]"
                            aria-hidden="true"
                        />
                        <Transition
                            mode="out-in"
                            enter-active-class="transition-opacity duration-300"
                            leave-active-class="transition-opacity duration-300"
                            enter-from-class="opacity-0"
                            leave-to-class="opacity-0"
                        >
                            <ReviewQuote
                                :key="REVIEWS[activeReview].key"
                                :review="REVIEWS[activeReview]"
                                class="[grid-area:1/1]"
                                :data-testid="`welcome-plan-review-${REVIEWS[activeReview].key}`"
                            />
                        </Transition>
                    </div>
                </div>

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
                spacious
                class="rounded-b-xl border border-border bg-card p-5 max-sm:border-t-0 sm:rounded-e-xl sm:rounded-es-none sm:border-s-0 sm:p-6"
            >
                <template #after>
                    <li
                        class="flex items-start gap-2 text-sm leading-5 text-foreground sm:text-base sm:leading-6"
                        :data-testid="`plan-highlight-${plan.slug}`"
                    >
                        <IconBuilding
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground sm:mt-1"
                        />
                        <span class="inline-flex items-start gap-1.5">
                            {{ $t('billing.plans.workspaces_one') }}
                            <PlanFeatureInfo
                                :label="$t('billing.plans.workspaces_tooltip')"
                                :testid="`plan-workspaces-info-${plan.slug}`"
                                spacious
                            />
                        </span>
                    </li>
                </template>
            </PlanFeatureList>
        </article>


        <template #actions>
            <WelcomeContinueButton
                :processing="form.processing"
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
        </template>
    </WelcomeLayout>
</template>
