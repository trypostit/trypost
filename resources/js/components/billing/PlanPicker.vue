<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export interface PlanOption {
    id: string;
    slug: string;
    name: string;
    workspace_limit: number | null;
}

const props = withDefaults(
    defineProps<{
        plans: PlanOption[];
        interval: 'monthly' | 'yearly';
        currentPlanId?: string | null;
        disabledPlanIds?: string[];
        processing?: boolean;
    }>(),
    {
        currentPlanId: null,
        disabledPlanIds: () => [],
        processing: false,
    },
);

const emit = defineEmits<{
    (event: 'update:interval', value: 'monthly' | 'yearly'): void;
    (event: 'select', planId: string): void;
}>();

const price = (plan: PlanOption): string =>
    trans(
        `billing.subscribe.prices.${plan.slug}.${props.interval === 'yearly' ? 'yearly_per_month' : 'monthly'}`,
    );

const workspacesLabel = (plan: PlanOption): string =>
    plan.workspace_limit === null
        ? trans('billing.plans.workspaces_unlimited')
        : trans('billing.plans.workspaces_one');

const isCurrent = (plan: PlanOption): boolean => plan.id === props.currentPlanId;
const isDisabled = (plan: PlanOption): boolean =>
    props.processing || isCurrent(plan) || props.disabledPlanIds.includes(plan.id);
</script>

<template>
    <div class="space-y-6">
        <div class="flex justify-center gap-2">
            <Button
                v-for="option in (['monthly', 'yearly'] as const)"
                :key="option"
                type="button"
                :variant="interval === option ? 'default' : 'outline'"
                :data-testid="`plan-interval-${option}`"
                @click="emit('update:interval', option)"
            >
                {{ $t(`billing.plans.${option}`) }}
                <Badge v-if="option === 'yearly'" variant="success" class="ml-2">
                    {{ $t('billing.plans.save_two_months') }}
                </Badge>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="plan in plans"
                :key="plan.id"
                class="space-y-4 rounded-2xl border-2 border-foreground bg-card p-6 shadow-2xs"
                :data-testid="`plan-card-${plan.slug}`"
            >
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-xl font-semibold" style="font-family: var(--font-display)">
                        {{ plan.name }}
                    </h3>
                    <Badge v-if="isCurrent(plan)" variant="secondary">
                        {{ $t('billing.plans.current') }}
                    </Badge>
                </div>

                <p class="text-foreground/70">
                    <span class="text-3xl font-bold tabular-nums text-foreground">{{ price(plan) }}</span>
                    <span class="ml-1">{{ $t('billing.plans.per_month') }}</span>
                </p>

                <p class="text-xs font-medium text-foreground/60">
                    {{ interval === 'yearly' ? $t('billing.subscribe.billed_yearly') : $t('billing.subscribe.billed_monthly') }}
                </p>

                <p class="flex items-center gap-2 text-sm font-medium text-foreground">
                    <IconCheck class="size-4 shrink-0" stroke-width="2.5" />
                    {{ workspacesLabel(plan) }}
                </p>

                <Button
                    type="button"
                    class="w-full"
                    :disabled="isDisabled(plan)"
                    :data-testid="`plan-select-${plan.slug}`"
                    @click="emit('select', plan.id)"
                >
                    {{ $t('billing.plans.select', { plan: plan.name }) }}
                </Button>
            </div>
        </div>
    </div>
</template>
