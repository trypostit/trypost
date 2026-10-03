<script setup lang="ts">
import {
    IconBuilding,
    IconCalendarEvent,
    IconChartBar,
    IconInfoCircle,
    IconRefresh,
    IconRobot,
    IconShare,
    IconSparkles,
    IconUsers,
    IconWorld,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import {
    PLAN_CHANGE_LABELS,
    planChangeAction,
    type BillingInterval,
    type PlanOption,
} from '@/types/plan';
import { Platform } from '@/types/platform';

export type { PlanOption };

interface PlanFeature {
    key: string;
    icon: Component;
    hasTooltip: boolean;
}

const props = withDefaults(
    defineProps<{
        plans: PlanOption[];
        interval: BillingInterval;
        currentPlanId?: string | null;
        currentInterval?: BillingInterval | null;
        disabledPlanIds?: string[];
        processing?: boolean;
        allowYearly?: boolean;
        offerFirstMonth?: boolean;
    }>(),
    {
        currentPlanId: null,
        currentInterval: null,
        disabledPlanIds: () => [],
        processing: false,
        allowYearly: true,
        offerFirstMonth: false,
    },
);

const emit = defineEmits<{
    (event: 'update:interval', value: BillingInterval): void;
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
    Platform.GoogleBusiness,
] as const;

const SHARED_FEATURES: Omit<PlanFeature, 'hasTooltip'>[] = [
    { key: 'accounts_unlimited', icon: IconShare },
    { key: 'calendar', icon: IconCalendarEvent },
    { key: 'ai', icon: IconSparkles },
    { key: 'mcp', icon: IconRobot },
    { key: 'repurpose', icon: IconRefresh },
    { key: 'analytics', icon: IconChartBar },
    { key: 'team', icon: IconUsers },
];

const FEATURES_WITH_TOOLTIP = new Set([
    'accounts_unlimited',
    'ai',
    'analytics',
    'calendar',
    'mcp',
    'repurpose',
    'team',
]);

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

const firstMonthPrice = (): string =>
    trans('billing.subscribe.prices.first_month');

const showsFirstMonthOffer = computed(
    (): boolean => props.offerFirstMonth && props.interval === 'monthly',
);

const billingNote = (plan: PlanOption): string =>
    props.interval === 'yearly'
        ? trans('billing.plans.billed_yearly_total', {
              price: yearlyTotal(plan),
          })
        : trans('billing.subscribe.billed_monthly');

const selectLabel = (plan: PlanOption): string => {
    if (props.offerFirstMonth) {
        return trans('billing.plans.start_first_month', {
            price: firstMonthPrice(),
        });
    }

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

/**
 * Labels stay as keys and resolve with `$t` in the template: the language JSON
 * loads asynchronously and `trans()` in a computed would cache the raw keys.
 */
const sharedFeatures: PlanFeature[] = SHARED_FEATURES.map((feature) => ({
    ...feature,
    hasTooltip: FEATURES_WITH_TOOLTIP.has(feature.key),
}));
</script>

<template>
    <div class="@container space-y-6">
        <div
            v-if="allowYearly"
            class="grid grid-cols-[1fr_auto_1fr] items-center gap-x-4"
        >
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

                <div v-if="showsFirstMonthOffer" class="flex flex-col gap-1">
                    <p class="flex items-baseline gap-2">
                        <span
                            class="text-2xl leading-9 font-strong tabular-nums"
                            :data-testid="`plan-price-first-month-${plan.slug}`"
                            >{{ firstMonthPrice() }}</span
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
                                price: price(plan),
                            })
                        }}
                    </p>
                </div>
                <div v-else class="flex flex-col gap-1">
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
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-foreground"
                    >
                        <span>{{ workspaceLabel(plan) }}</span>
                        <TooltipProvider :delay-duration="200">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="inline-flex size-4 shrink-0 cursor-pointer items-center justify-center text-muted-foreground transition-control hover:text-foreground"
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
                                    class="max-w-64 text-start"
                                >
                                    {{ $t('billing.plans.workspaces_tooltip') }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </p>
                </div>

                <div
                    class="flex flex-1 flex-col gap-3 border-t border-border pt-4"
                >
                    <p class="text-sm font-medium text-muted-foreground">
                        {{ $t('billing.plans.everything_included') }}
                    </p>

                    <ul class="flex flex-col gap-1">
                        <li
                            class="flex items-center gap-2 text-sm text-foreground"
                        >
                            <IconWorld
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="inline-flex items-center gap-1.5">
                                <span>{{
                                    $t('billing.plans.features.networks_all')
                                }}</span>
                                <TooltipProvider :delay-duration="200">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button
                                                type="button"
                                                class="inline-flex size-4 shrink-0 cursor-pointer items-center justify-center text-muted-foreground transition-control hover:text-foreground"
                                                :aria-label="
                                                    $t(
                                                        'billing.plans.features.networks_all_tooltip',
                                                    )
                                                "
                                                :data-testid="`plan-networks-info-${plan.slug}`"
                                            >
                                                <IconInfoCircle
                                                    class="size-4"
                                                />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            side="top"
                                            :side-offset="8"
                                            class="w-fit max-w-none space-y-2 p-3"
                                        >
                                            <p class="font-medium">
                                                {{
                                                    $t(
                                                        'billing.plans.features.networks_all_tooltip',
                                                    )
                                                }}
                                            </p>
                                            <div
                                                class="grid grid-cols-4 gap-1"
                                                :data-testid="`plan-networks-${plan.slug}`"
                                            >
                                                <span
                                                    v-for="network in PLAN_NETWORKS"
                                                    :key="network"
                                                    class="flex w-16 flex-col items-center gap-1.5 rounded-md bg-background/10 px-1.5 py-2"
                                                >
                                                    <PlatformLogo
                                                        :platform="network"
                                                        size="xs"
                                                        plain
                                                        :title="null"
                                                    />
                                                    <span
                                                        class="max-w-full truncate text-[10px] leading-none font-medium text-background/80"
                                                    >
                                                        {{
                                                            getPlatformLabel(
                                                                network,
                                                            )
                                                        }}
                                                    </span>
                                                </span>
                                            </div>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </span>
                        </li>

                        <li
                            v-for="feature in sharedFeatures"
                            :key="feature.key"
                            class="flex items-center gap-2 text-sm text-foreground"
                        >
                            <component
                                :is="feature.icon"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="inline-flex items-center gap-1.5">
                                <span>{{
                                    $t(`billing.plans.features.${feature.key}`)
                                }}</span>
                                <TooltipProvider
                                    v-if="feature.hasTooltip"
                                    :delay-duration="200"
                                >
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button
                                                type="button"
                                                class="inline-flex size-4 shrink-0 cursor-pointer items-center justify-center text-muted-foreground transition-control hover:text-foreground"
                                                :aria-label="
                                                    $t(
                                                        `billing.plans.features.${feature.key}_tooltip`,
                                                    )
                                                "
                                                :data-testid="`plan-feature-info-${plan.slug}-${feature.key}`"
                                            >
                                                <IconInfoCircle
                                                    class="size-4"
                                                />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            side="top"
                                            :side-offset="8"
                                            class="max-w-56 text-start"
                                        >
                                            {{
                                                $t(
                                                    `billing.plans.features.${feature.key}_tooltip`,
                                                )
                                            }}
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </span>
                        </li>
                    </ul>
                </div>
            </article>
        </div>
    </div>
</template>
