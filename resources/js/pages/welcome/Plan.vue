<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconBuilding,
    IconInfoCircle,
    IconStarFilled,
} from '@tabler/icons-vue';
import { useIntervalFn, useMediaQuery } from '@vueuse/core';
import { ref, watch } from 'vue';

import PlanFeatureList from '@/components/billing/PlanFeatureList.vue';
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

const REVIEW_INTERVAL_MS = 6000;

const activeReview = ref(0);
const prefersReducedMotion = useMediaQuery('(prefers-reduced-motion: reduce)');

const showNextReview = (): void => {
    activeReview.value = (activeReview.value + 1) % REVIEWS.length;
};

const { pause, resume } = useIntervalFn(showNextReview, REVIEW_INTERVAL_MS, {
    immediate: false,
});

const pauseReviews = (): void => {
    pause();
};

const resumeReviews = (): void => {
    if (!prefersReducedMotion.value) {
        resume();
    }
};

watch(
    prefersReducedMotion,
    (reduced) => {
        if (reduced) {
            pause();
        } else {
            resume();
        }
    },
    { immediate: true },
);

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
                <div
                    class="flex w-full flex-col gap-6"
                    data-testid="welcome-plan-reviews"
                    @mouseenter="pauseReviews"
                    @mouseleave="resumeReviews"
                >
                    <div class="flex items-center gap-3">
                        <div class="flex -space-x-2 rtl:space-x-reverse">
                            <img
                                v-for="(review, index) in REVIEWS"
                                :key="review.key"
                                :src="review.photo"
                                :alt="review.name"
                                width="72"
                                height="72"
                                :class="[
                                    'size-9 rounded-full object-cover ring-2 ring-primary-subtle transition-opacity duration-500 dark:ring-primary-selected',
                                    index === activeReview ? 'opacity-100' : 'opacity-50',
                                ]"
                            />
                        </div>
                        <span class="flex flex-col gap-1">
                            <span class="flex gap-0.5">
                                <IconStarFilled
                                    v-for="star in 5"
                                    :key="star"
                                    class="size-3.5 text-amber-500"
                                />
                            </span>
                            <span class="text-sm font-medium text-foreground">
                                {{ $t('auth.reviews.eyebrow') }}
                            </span>
                        </span>
                    </div>

                    <div class="grid">
                        <figure
                            v-for="(review, index) in REVIEWS"
                            :key="review.key"
                            :class="[
                                'flex flex-col gap-2 transition-opacity duration-500 [grid-area:1/1]',
                                index === activeReview ? 'opacity-100' : 'opacity-0',
                            ]"
                            :aria-hidden="index !== activeReview"
                            :data-testid="`welcome-plan-review-${review.key}`"
                        >
                            <blockquote class="text-sm text-foreground">
                                “{{ $t(`auth.reviews.${review.key}.quote`) }}”
                            </blockquote>
                            <figcaption class="text-xs text-muted-foreground">
                                <span class="font-medium text-foreground">{{
                                    review.name
                                }}</span>
                                <span class="block">{{
                                    $t(`auth.reviews.${review.key}.role`)
                                }}</span>
                            </figcaption>
                        </figure>
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
        </template>
    </WelcomeLayout>
</template>
