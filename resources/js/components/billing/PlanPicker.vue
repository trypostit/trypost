<script setup lang="ts">
import {
    IconBuilding,
    IconCalendarEvent,
    IconChartBar,
    IconRefresh,
    IconRobot,
    IconShare,
    IconSparkles,
    IconUsers,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Platform } from '@/types/platform';

export interface PlanOption {
    id: string;
    slug: string;
    name: string;
    workspace_limit: number | null;
}

interface PlanFeature {
    key: string;
    label: string;
    icon: Component;
    tone: string;
    emphasize: boolean;
}

const props = withDefaults(
    defineProps<{
        plans: PlanOption[];
        interval: 'monthly' | 'yearly';
        currentPlanId?: string | null;
        disabledPlanIds?: string[];
        processing?: boolean;
        allowYearly?: boolean;
        offerFirstMonth?: boolean;
    }>(),
    {
        currentPlanId: null,
        disabledPlanIds: () => [],
        processing: false,
        allowYearly: true,
        offerFirstMonth: false,
    },
);

const emit = defineEmits<{
    (event: 'update:interval', value: 'monthly' | 'yearly'): void;
    (event: 'select', planId: string): void;
}>();

const PLAN_NETWORKS = [
    Platform.Instagram,
    Platform.Facebook,
    Platform.LinkedIn,
    Platform.X,
    Platform.TikTok,
    Platform.YouTube,
    Platform.Pinterest,
    Platform.Threads,
    Platform.Bluesky,
    Platform.Mastodon,
    Platform.Telegram,
    Platform.Discord,
] as const;

const SHARED_FEATURES: Omit<PlanFeature, 'label' | 'emphasize'>[] = [
    { key: 'calendar', icon: IconCalendarEvent, tone: 'bg-blue-200' },
    { key: 'ai', icon: IconSparkles, tone: 'bg-pink-200' },
    { key: 'mcp', icon: IconRobot, tone: 'bg-violet-200' },
    { key: 'repurpose', icon: IconRefresh, tone: 'bg-amber-200' },
    { key: 'analytics', icon: IconChartBar, tone: 'bg-emerald-200' },
    { key: 'team', icon: IconUsers, tone: 'bg-purple-200' },
];

const price = (plan: PlanOption): string =>
    trans(
        `billing.subscribe.prices.${plan.slug}.${props.interval === 'yearly' ? 'yearly_per_month' : 'monthly'}`,
    );

const yearlyTotal = (plan: PlanOption): string => trans(`billing.subscribe.prices.${plan.slug}.yearly`);

const tagline = (plan: PlanOption): string => trans(`billing.plans.${plan.slug}_tagline`);

const isCurrent = (plan: PlanOption): boolean => plan.id === props.currentPlanId;

const isDisabled = (plan: PlanOption): boolean =>
    props.processing || isCurrent(plan) || props.disabledPlanIds.includes(plan.id);

const isFeatured = (plan: PlanOption): boolean => plan.slug === 'workspaces';

const firstMonthPrice = (): string => trans('billing.subscribe.prices.first_month');

const billingNote = (plan: PlanOption): string => {
    if (props.offerFirstMonth) {
        return trans('billing.plans.first_month_then', {
            first: firstMonthPrice(),
            price: price(plan),
        });
    }

    return props.interval === 'yearly'
        ? trans('billing.plans.billed_yearly_total', { price: yearlyTotal(plan) })
        : trans('billing.subscribe.billed_monthly');
};

const selectLabel = (plan: PlanOption): string =>
    props.offerFirstMonth
        ? trans('billing.plans.start_first_month', { price: firstMonthPrice() })
        : trans('billing.plans.select', { plan: plan.name });

const featuresFor = (plan: PlanOption): PlanFeature[] => {
    const unlimited = plan.workspace_limit === null;

    const differentiators: PlanFeature[] = unlimited
        ? [
              {
                  key: 'workspaces',
                  label: trans('billing.plans.workspaces_unlimited'),
                  icon: IconBuilding,
                  tone: 'bg-violet-200',
                  emphasize: true,
              },
              {
                  key: 'accounts',
                  label: trans('billing.plans.features.accounts_unlimited'),
                  icon: IconShare,
                  tone: 'bg-sky-200',
                  emphasize: false,
              },
          ]
        : [
              {
                  key: 'accounts',
                  label: trans('billing.plans.features.accounts_unlimited'),
                  icon: IconShare,
                  tone: 'bg-sky-200',
                  emphasize: true,
              },
              {
                  key: 'workspaces',
                  label: trans('billing.plans.workspaces_one'),
                  icon: IconBuilding,
                  tone: 'bg-amber-200',
                  emphasize: false,
              },
          ];

    return [
        ...differentiators,
        ...SHARED_FEATURES.map((feature) => ({
            ...feature,
            label: trans(`billing.plans.features.${feature.key}`),
            emphasize: false,
        })),
    ];
};
</script>

<template>
    <div class="space-y-6">
        <div v-if="allowYearly" class="flex justify-center">
            <div class="inline-flex rounded-full border-2 border-foreground bg-card p-1 shadow-2xs">
                <Button
                    v-for="option in (['monthly', 'yearly'] as const)"
                    :key="option"
                    type="button"
                    size="sm"
                    :variant="interval === option ? 'default' : 'ghost'"
                    class="rounded-full"
                    :data-testid="`plan-interval-${option}`"
                    @click="emit('update:interval', option)"
                >
                    {{ $t(`billing.plans.${option}`) }}
                    <Badge v-if="option === 'yearly'" variant="success" class="ml-1.5">
                        {{ $t('billing.plans.save_two_months') }}
                    </Badge>
                </Button>
            </div>
        </div>

        <div
            class="flex flex-nowrap items-center justify-center gap-1.5"
            data-testid="plan-networks"
        >
            <PlatformLogo
                v-for="network in PLAN_NETWORKS"
                :key="network"
                :platform="network"
                size="xs"
                plain
            />
        </div>

        <div class="grid items-stretch gap-4 lg:grid-cols-2">
            <article
                v-for="plan in plans"
                :key="plan.id"
                class="flex flex-col gap-5 rounded-2xl border-2 border-foreground p-6 shadow-2xs"
                :class="isFeatured(plan) ? 'bg-violet-50 dark:bg-violet-950/30' : 'bg-card'"
                :data-testid="`plan-card-${plan.slug}`"
            >
                <div class="space-y-3">
                    <Badge v-if="isCurrent(plan)" variant="secondary">
                        {{ $t('billing.plans.current') }}
                    </Badge>

                    <div class="space-y-1">
                        <h3 class="text-2xl font-semibold" style="font-family: var(--font-display)">
                            {{ plan.name }}
                        </h3>
                        <p class="text-sm text-foreground/70">
                            {{ tagline(plan) }}
                        </p>
                    </div>

                    <p>
                        <span class="text-4xl font-bold tabular-nums text-foreground">{{ price(plan) }}</span>
                        <span class="ml-1 text-foreground/70">{{ $t('billing.plans.per_month') }}</span>
                    </p>
                    <p class="text-xs font-medium text-foreground/60">
                        {{ billingNote(plan) }}
                    </p>
                </div>

                <ul class="flex flex-1 flex-col gap-2.5">
                    <li
                        v-for="feature in featuresFor(plan)"
                        :key="feature.key"
                        class="flex items-start gap-2.5 text-sm text-foreground"
                        :class="feature.emphasize ? 'font-bold' : 'font-medium'"
                        :data-testid="feature.emphasize ? `plan-highlight-${plan.slug}` : undefined"
                    >
                        <span
                            class="inline-flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-foreground"
                            :class="feature.tone"
                        >
                            <component :is="feature.icon" class="size-3.5" stroke-width="2.25" />
                        </span>
                        <span>{{ feature.label }}</span>
                    </li>
                </ul>

                <Button
                    type="button"
                    class="mt-auto w-full"
                    :disabled="isDisabled(plan)"
                    :data-testid="`plan-select-${plan.slug}`"
                    @click="emit('select', plan.id)"
                >
                    {{ selectLabel(plan) }}
                </Button>
            </article>
        </div>
    </div>
</template>
