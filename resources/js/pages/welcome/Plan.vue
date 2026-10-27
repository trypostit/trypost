<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconStarFilled } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlanFeatureList from '@/components/billing/PlanFeatureList.vue';
import ReviewQuote from '@/components/reviews/ReviewQuote.vue';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import { useAutoRotate } from '@/composables/useAutoRotate';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { REVIEWS } from '@/lib/reviews';
import { store } from '@/routes/app/welcome/plan';
import type { Offer } from '@/types/offer';
import type { PlanOption } from '@/types/plan';

const props = defineProps<{
    plan: PlanOption;
    offer: Offer | null;
}>();

const showsOfferPrice = computed((): boolean => props.offer?.has_price === true);

const isTrialOnly = computed(
    (): boolean =>
        Boolean(props.offer?.trial_days) && props.offer?.has_coupon === false,
);

const checkoutPriceKey = computed((): string =>
    showsOfferPrice.value
        ? `offers.${props.offer?.key}.price`
        : 'billing.subscribe.prices.socials.monthly',
);

const form = useForm({});

const {
    active: activeReview,
    select: selectReview,
    pause: pauseReviews,
    resume: resumeReviews,
    stop: stopReviews,
} = useAutoRotate(REVIEWS.length, 6000);

const pickReview = (index: number): void => {
    selectReview(index);
    stopReviews();
};

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
        <div
            v-if="offer?.has_banner"
            class="mb-6 flex justify-center"
            data-testid="welcome-plan-offer"
        >
            <p
                class="inline-flex items-center gap-3 rounded-full bg-foreground py-1.5 ps-1.5 pe-5 text-start text-sm text-background sm:text-base"
            >
                <span
                    class="shrink-0 rounded-full bg-background px-3 py-1 text-xs font-strong tracking-wider text-foreground uppercase sm:text-sm"
                    data-testid="welcome-plan-offer-name"
                    >{{ $t(`offers.${offer.key}.name`) }}</span
                >
                <span data-testid="welcome-plan-offer-headline">{{
                    $t(`offers.${offer.key}.headline`)
                }}</span>
            </p>
        </div>

        <article
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
                    @focusin="pauseReviews"
                    @focusout="resumeReviews"
                >
                    <div class="flex -space-x-2 rtl:space-x-reverse">
                        <button
                            v-for="(review, index) in REVIEWS"
                            :key="review.key"
                            type="button"
                            :aria-label="review.name"
                            :aria-pressed="index === activeReview"
                            :class="[
                                'relative cursor-pointer rounded-full transition-[transform,opacity] duration-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                index === activeReview
                                    ? 'z-10 scale-115 shadow-md'
                                    : 'opacity-50 hover:z-10 hover:opacity-100',
                            ]"
                            :data-testid="`welcome-plan-reviewer-${review.key}`"
                            @click="pickReview(index)"
                        >
                            <img
                                :src="review.photo"
                                alt=""
                                width="72"
                                height="72"
                                class="size-9 rounded-full border border-card object-cover"
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

                <div class="flex flex-col gap-1">
                    <s
                        v-if="offer && showsOfferPrice"
                        class="text-sm text-muted-foreground"
                        :data-testid="`plan-price-original-${plan.slug}`"
                        >{{ $t('billing.subscribe.prices.socials.monthly')
                        }}{{ $t('billing.plans.per_month') }}</s
                    >
                    <p class="flex items-baseline gap-1 whitespace-nowrap">
                        <span
                            class="text-4xl leading-none font-strong tracking-tight tabular-nums [word-spacing:-0.15em] sm:text-5xl"
                            :data-testid="`plan-price-${plan.slug}`"
                            >{{ $t(checkoutPriceKey) }}</span
                        >
                        <span
                            v-if="!isTrialOnly"
                            class="text-base text-foreground"
                            :data-testid="`plan-price-unit-${plan.slug}`"
                            >{{ $t('billing.plans.per_month') }}</span
                        >
                    </p>
                    <span
                        v-if="offer && showsOfferPrice"
                        class="text-base text-foreground"
                        :data-testid="`plan-price-suffix-${plan.slug}`"
                        >{{ $t(`offers.${offer.key}.period`) }}</span
                    >
                </div>
            </div>

            <PlanFeatureList
                :scope="plan.slug"
                spacious
                class="rounded-b-xl border border-border bg-card p-5 max-sm:border-t-0 sm:rounded-e-xl sm:rounded-es-none sm:border-s-0 sm:p-6"
            />
        </article>

        <template #actions>
            <WelcomeContinueButton
                :processing="form.processing"
                :disabled="form.processing"
                :label="
                    isTrialOnly && showsOfferPrice
                        ? $t('welcome.plan_start_trial')
                        : $t('billing.plans.start_first_month', {
                              price: $t(checkoutPriceKey),
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
                    showsOfferPrice
                        ? $t('welcome.plan_terms', {
                              price: $t('billing.subscribe.prices.socials.monthly'),
                          })
                        : $t('welcome.plan_cancel_anytime')
                }}
            </p>
        </template>
    </WelcomeLayout>
</template>
