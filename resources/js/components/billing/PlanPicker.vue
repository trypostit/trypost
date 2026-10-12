<script setup lang="ts">
import { IconBuilding } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import PlanFeatureInfo from '@/components/billing/PlanFeatureInfo.vue';
import PlanFeatureList from '@/components/billing/PlanFeatureList.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    PLAN_CHANGE_LABELS,
    planChangeAction,
    type BillingInterval,
    type PlanOption,
} from '@/types/plan';

export type { PlanOption };

const props = withDefaults(
    defineProps<{
        plans: PlanOption[];
        interval: BillingInterval;
        currentPlanId?: string | null;
        currentInterval?: BillingInterval | null;
        disabledPlanIds?: string[];
        processing?: boolean;
    }>(),
    {
        currentPlanId: null,
        currentInterval: null,
        disabledPlanIds: () => [],
        processing: false,
    },
);

const emit = defineEmits<{
    (event: 'update:interval', value: BillingInterval): void;
    (event: 'select', planId: string): void;
}>();

const price = (plan: PlanOption): string =>
    trans(
        `billing.subscribe.prices.${plan.slug}.${props.interval === 'yearly' ? 'yearly_per_month' : 'monthly'}`,
    );

const yearlyTotal = (plan: PlanOption): string =>
    trans(`billing.subscribe.prices.${plan.slug}.yearly`);

const tagline = (plan: PlanOption): string =>
    trans(`billing.plans.${plan.slug}_tagline`);

const isCurrentPlan = (plan: PlanOption): boolean =>
    plan.id === props.currentPlanId;

const currentPlan = computed(
    (): PlanOption | null =>
        props.plans.find((plan) => plan.id === props.currentPlanId) ?? null,
);

const isCurrentSelection = (plan: PlanOption): boolean =>
    isCurrentPlan(plan) &&
    props.currentInterval !== null &&
    props.currentInterval === props.interval;

const isDisabled = (plan: PlanOption): boolean =>
    props.processing ||
    isCurrentSelection(plan) ||
    props.disabledPlanIds.includes(plan.id);

const isFeatured = (plan: PlanOption): boolean => plan.slug === 'workspaces';

const billingNote = (plan: PlanOption): string =>
    props.interval === 'yearly'
        ? trans('billing.plans.billed_yearly_total', {
              price: yearlyTotal(plan),
          })
        : trans('billing.subscribe.billed_monthly');

const selectLabel = (plan: PlanOption): string => {
    if (isCurrentSelection(plan)) {
        return trans('billing.plans.current');
    }

    if (isCurrentPlan(plan)) {
        return props.interval === 'yearly'
            ? trans('billing.plans.switch_to_yearly')
            : trans('billing.plans.switch_to_monthly');
    }

    return trans(
        PLAN_CHANGE_LABELS[planChangeAction(currentPlan.value, plan)],
        {
            plan: plan.name,
        },
    );
};

const isUnlimited = (plan: PlanOption): boolean =>
    plan.workspace_limit === null;

const workspaceLabel = (plan: PlanOption): string =>
    isUnlimited(plan)
        ? trans('billing.plans.workspaces_unlimited')
        : trans('billing.plans.workspaces_one');
</script>

<template>
    <div class="@container space-y-6">
        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-x-4">
            <div
                class="col-start-2 inline-flex gap-1 justify-self-center rounded-lg border border-border-strong bg-card p-[3px]"
            >
                <button
                    v-for="option in ['monthly', 'yearly'] as const"
                    :key="option"
                    type="button"
                    :aria-pressed="interval === option"
                    :class="[
                        'h-[30px] cursor-pointer rounded-md px-3 text-sm font-medium transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                        interval === option
                            ? 'bg-primary-selected text-primary-text'
                            : 'text-foreground hover:bg-accent',
                    ]"
                    :data-testid="`plan-interval-${option}`"
                    @click="emit('update:interval', option)"
                >
                    {{ $t(`billing.plans.${option}`) }}
                </button>
            </div>
            <Badge
                variant="success"
                class="col-start-3 justify-self-start"
                data-testid="plan-save-two-months"
            >
                {{ $t('billing.plans.save_two_months') }}
            </Badge>
        </div>

        <div class="grid items-stretch gap-4 @2xl:grid-cols-2">
            <article
                v-for="plan in plans"
                :key="plan.id"
                class="flex flex-col gap-4 rounded-xl border bg-card p-5 text-start"
                :class="
                    isFeatured(plan) ? 'border-primary-strong' : 'border-border'
                "
                :data-testid="`plan-card-${plan.slug}`"
            >
                <div class="flex flex-col gap-1">
                    <div class="flex w-full items-center justify-between gap-3">
                        <h3 class="text-lg leading-tight font-medium">
                            {{ plan.name }}
                        </h3>
                        <Badge
                            v-if="isCurrentSelection(plan)"
                            variant="secondary"
                            class="shrink-0"
                        >
                            {{ $t('billing.plans.current') }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ tagline(plan) }}
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <p class="flex items-baseline gap-2">
                        <span
                            class="text-2xl leading-9 font-strong tabular-nums"
                            :data-testid="`plan-price-${plan.slug}`"
                            >{{ price(plan) }}</span
                        >
                        <span class="text-base text-foreground">{{
                            $t('billing.plans.per_month')
                        }}</span>
                    </p>
                    <p
                        class="text-sm text-muted-foreground"
                        :data-testid="`plan-price-note-${plan.slug}`"
                    >
                        {{ billingNote(plan) }}
                    </p>
                </div>

                <Button
                    type="button"
                    size="lg"
                    :variant="isFeatured(plan) ? 'default' : 'outline'"
                    class="w-full"
                    :disabled="isDisabled(plan)"
                    :data-testid="`plan-select-${plan.slug}`"
                    @click="emit('select', plan.id)"
                >
                    {{ selectLabel(plan) }}
                </Button>

                <div
                    class="flex items-center gap-3 rounded-lg bg-muted px-3 py-2"
                    :data-testid="`plan-highlight-${plan.slug}`"
                >
                    <span
                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-card"
                    >
                        <IconBuilding class="size-4" />
                    </span>
                    <p
                        class="inline-flex items-start gap-1.5 text-sm leading-5 font-medium text-foreground"
                    >
                        <span>{{ workspaceLabel(plan) }}</span>
                        <PlanFeatureInfo
                            :label="$t('billing.plans.workspaces_tooltip')"
                            :testid="`plan-workspaces-info-${plan.slug}`"
                        />
                    </p>
                </div>

                <PlanFeatureList
                    :scope="plan.slug"
                    class="flex-1 border-t border-border pt-4"
                />
            </article>
        </div>
    </div>
</template>
