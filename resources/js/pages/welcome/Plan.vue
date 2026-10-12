<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconBuilding, IconInfoCircle } from '@tabler/icons-vue';

import PlanFeatureList from '@/components/billing/PlanFeatureList.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/plan';
import type { PlanOption } from '@/types/plan';

defineProps<{
    plans: PlanOption[];
}>();

const form = useForm({});

const submit = (): void => {
    if (form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.plan_title')" />

    <WelcomeLayout :title="$t('welcome.plan_title')" step="plan">
        <article
            v-for="plan in plans"
            :key="plan.id"
            class="grid overflow-hidden rounded-xl border border-border bg-card text-start sm:grid-cols-[2fr_3fr]"
            :data-testid="`plan-card-${plan.slug}`"
        >
            <div
                class="flex flex-col justify-center bg-primary-subtle p-5 sm:p-6 dark:bg-primary-selected"
            >
                <div class="flex flex-col gap-1">
                    <p class="flex items-baseline gap-2">
                        <span
                            class="text-4xl leading-none font-strong tabular-nums"
                            :data-testid="`plan-price-first-month-${plan.slug}`"
                            >{{
                                $t('billing.subscribe.prices.first_month')
                            }}</span
                        >
                        <span
                            class="text-base text-foreground"
                            :data-testid="`plan-price-suffix-${plan.slug}`"
                            >{{ $t('billing.plans.per_first_month') }}</span
                        >
                    </p>
                    <p
                        class="text-sm text-muted-foreground"
                        :data-testid="`plan-price-note-${plan.slug}`"
                    >
                        {{
                            $t('billing.plans.then_monthly', {
                                price: $t(
                                    `billing.subscribe.prices.${plan.slug}.monthly`,
                                ),
                            })
                        }}
                    </p>
                </div>
            </div>

            <PlanFeatureList
                :scope="plan.slug"
                class="p-5 sm:p-6"
            >
                <template #before>
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
        </template>
    </WelcomeLayout>
</template>
