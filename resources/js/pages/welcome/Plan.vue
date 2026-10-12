<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconBuilding } from '@tabler/icons-vue';

import PlanFeatureList from '@/components/billing/PlanFeatureList.vue';
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
            class="grid gap-6 rounded-xl border border-border bg-card p-5 text-start sm:grid-cols-2 sm:gap-8 sm:p-6"
            :data-testid="`plan-card-${plan.slug}`"
        >
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <h3 class="text-lg leading-tight font-medium">
                        {{ plan.name }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ $t(`billing.plans.${plan.slug}_tagline`) }}
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <p class="flex items-baseline gap-2">
                        <span
                            class="text-3xl leading-10 font-strong tabular-nums"
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

                <div
                    class="flex items-center gap-3 rounded-lg bg-muted px-3 py-2"
                    :data-testid="`plan-highlight-${plan.slug}`"
                >
                    <span
                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-card"
                    >
                        <IconBuilding class="size-4" />
                    </span>
                    <p class="text-sm font-medium text-foreground">
                        {{ $t('billing.plans.workspaces_one') }}
                    </p>
                </div>
            </div>

            <PlanFeatureList
                :scope="plan.slug"
                class="max-sm:border-t max-sm:border-border max-sm:pt-6 sm:border-s sm:border-border sm:ps-8"
            />
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
