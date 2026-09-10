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
import { Platform } from '@/types/platform';
import {
    PLAN_CHANGE_LABELS,
    planChangeAction,
    type BillingInterval,
    type PlanOption,
} from '@/types/plan';

export type { PlanOption };

interface PlanFeature {
    key: string;
    label: string;
    icon: Component;
    tone: string;
    tooltip?: string;
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
] as const;

const SHARED_FEATURES: Omit<PlanFeature, 'label' | 'tooltip'>[] = [
    { key: 'accounts_unlimited', icon: IconShare, tone: 'bg-sky-200' },
    { key: 'calendar', icon: IconCalendarEvent, tone: 'bg-blue-200' },
    { key: 'ai', icon: IconSparkles, tone: 'bg-pink-200' },
    { key: 'mcp', icon: IconRobot, tone: 'bg-violet-200' },
    { key: 'repurpose', icon: IconRefresh, tone: 'bg-amber-200' },
    { key: 'analytics', icon: IconChartBar, tone: 'bg-emerald-200' },
    { key: 'team', icon: IconUsers, tone: 'bg-purple-200' },
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

    return trans(PLAN_CHANGE_LABELS[planChangeAction(currentPlan.value, plan)], {
        plan: plan.name,
    });
};

const isUnlimited = (plan: PlanOption): boolean =>
    plan.workspace_limit === null;

const workspaceLabel = (plan: PlanOption): string =>
    isUnlimited(plan)
        ? trans('billing.plans.workspaces_unlimited')
        : trans('billing.plans.workspaces_one');

/**
 * Computed rather than a plain const: the language JSON loads asynchronously,
 * and a one-off `trans()` at setup time would freeze the raw keys forever.
 */
const sharedFeatures = computed<PlanFeature[]>(() =>
    SHARED_FEATURES.map((feature) => ({
        ...feature,
        label: trans(`billing.plans.features.${feature.key}`),
        tooltip: FEATURES_WITH_TOOLTIP.has(feature.key)
            ? trans(`billing.plans.features.${feature.key}_tooltip`)
            : undefined,
    })),
);
</script>

<template>
    <div class="@container space-y-6">
        <div
            v-if="allowYearly"
            class="grid grid-cols-[1fr_auto_1fr] items-center gap-x-4"
        >
            <div
                class="col-start-2 inline-flex isolate justify-self-center gap-1.5 rounded-full border-2 border-foreground bg-card p-1 shadow-2xs"
            >
                <Button
                    v-for="option in ['monthly', 'yearly'] as const"
                    :key="option"
                    type="button"
                    size="sm"
                    :variant="interval === option ? 'default' : 'ghost'"
                    class="rounded-full"
                    :class="
                        interval === option
                            ? 'relative z-10'
                            : 'border-2 border-transparent hover:border-transparent'
                    "
                    :data-testid="`plan-interval-${option}`"
                    @click="emit('update:interval', option)"
                >
                    {{ $t(`billing.plans.${option}`) }}
                </Button>
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
                class="flex flex-col gap-4 rounded-2xl border-2 border-foreground p-5 shadow-2xs"
                :class="
                    isFeatured(plan)
                        ? 'bg-violet-50 dark:bg-violet-950/30'
                        : 'bg-card'
                "
                :data-testid="`plan-card-${plan.slug}`"
            >
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col items-start gap-1.5 text-start">
                        <div
                            class="flex w-full items-center justify-between gap-3"
                        >
                            <h3 class="text-xl font-bold tracking-tight">
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
                        <p class="text-sm text-foreground/70">
                            {{ tagline(plan) }}
                        </p>
                    </div>

                    <div
                        v-if="showsFirstMonthOffer"
                        class="flex flex-col items-center text-center"
                    >
                        <p class="flex items-baseline justify-center gap-1">
                            <span
                                class="text-5xl leading-none font-bold tracking-tight tabular-nums"
                                :data-testid="`plan-price-first-month-${plan.slug}`"
                                >{{ firstMonthPrice() }}</span
                            >
                            <span
                                class="text-lg font-semibold text-foreground/80"
                                :data-testid="`plan-price-suffix-${plan.slug}`"
                                >{{ $t('billing.plans.per_first_month') }}</span
                            >
                        </p>
                        <p
                            class="mt-1.5 text-sm font-medium text-foreground/60"
                            :data-testid="`plan-price-note-${plan.slug}`"
                        >
                            {{
                                $t('billing.plans.then_monthly', {
                                    price: price(plan),
                                })
                            }}
                        </p>
                    </div>
                    <div v-else class="flex flex-col items-center text-center">
                        <p class="flex items-baseline gap-1.5">
                            <span
                                class="text-4xl leading-none font-bold tracking-tight tabular-nums"
                                :data-testid="`plan-price-${plan.slug}`"
                                >{{ price(plan) }}</span
                            >
                            <span class="text-base text-foreground/70">{{
                                $t('billing.plans.per_month')
                            }}</span>
                        </p>
                        <p
                            class="mt-1.5 text-xs font-medium text-foreground/60"
                            :data-testid="`plan-price-note-${plan.slug}`"
                        >
                            {{ billingNote(plan) }}
                        </p>
                    </div>
                </div>

                <div
                    class="flex items-center gap-3 rounded-xl border-2 border-foreground px-3.5 py-2.5 text-start shadow-2xs"
                    :class="
                        isUnlimited(plan) ? 'bg-violet-200' : 'bg-amber-200'
                    "
                    :data-testid="`plan-highlight-${plan.slug}`"
                >
                    <span
                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg border-2 border-foreground bg-card"
                    >
                        <IconBuilding class="size-4.5" stroke-width="2.25" />
                    </span>
                    <p
                        class="inline-flex items-center gap-1.5 text-base font-bold text-foreground"
                    >
                        <span>{{ workspaceLabel(plan) }}</span>
                        <TooltipProvider :delay-duration="200">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="inline-flex size-4 shrink-0 items-center justify-center text-foreground/55 transition-colors hover:text-foreground"
                                        :aria-label="
                                            $t(
                                                'billing.plans.workspaces_tooltip',
                                            )
                                        "
                                        :data-testid="`plan-workspaces-info-${plan.slug}`"
                                    >
                                        <IconInfoCircle
                                            class="size-4"
                                            stroke-width="2.25"
                                        />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="top"
                                    :side-offset="8"
                                    class="max-w-64 rounded-xl p-3 text-start"
                                >
                                    <p
                                        class="text-xs leading-snug font-medium text-background"
                                    >
                                        {{
                                            $t(
                                                'billing.plans.workspaces_tooltip',
                                            )
                                        }}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </p>
                </div>

                <div class="flex flex-1 flex-col gap-2.5 text-start">
                    <p
                        class="text-xs font-bold tracking-wide text-foreground/60 uppercase"
                    >
                        {{ $t('billing.plans.everything_included') }}
                    </p>

                    <ul class="flex flex-col gap-1.5">
                        <li
                            class="flex items-center gap-2.5 text-sm font-medium text-foreground"
                        >
                            <span
                                class="inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground bg-rose-200"
                            >
                                <IconWorld class="size-3" stroke-width="2.25" />
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 leading-tight"
                            >
                                <span>{{
                                    $t('billing.plans.features.networks_all')
                                }}</span>
                                <TooltipProvider :delay-duration="200">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button
                                                type="button"
                                                class="inline-flex size-4 shrink-0 items-center justify-center text-foreground/55 transition-colors hover:text-foreground"
                                                :aria-label="
                                                    $t(
                                                        'billing.plans.features.networks_all_tooltip',
                                                    )
                                                "
                                                :data-testid="`plan-networks-info-${plan.slug}`"
                                            >
                                                <IconInfoCircle
                                                    class="size-4"
                                                    stroke-width="2.25"
                                                />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            side="top"
                                            :side-offset="8"
                                            class="w-fit max-w-none space-y-2.5 rounded-xl p-3"
                                        >
                                            <p
                                                class="text-xs font-semibold text-background"
                                            >
                                                {{
                                                    $t(
                                                        'billing.plans.features.networks_all_tooltip',
                                                    )
                                                }}
                                            </p>
                                            <div
                                                class="grid grid-cols-4 gap-1.5"
                                                :data-testid="`plan-networks-${plan.slug}`"
                                            >
                                                <span
                                                    v-for="network in PLAN_NETWORKS"
                                                    :key="network"
                                                    class="flex w-16 flex-col items-center gap-1.5 rounded-lg bg-background/10 px-1.5 py-2"
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
                            class="flex items-center gap-2.5 text-sm font-medium text-foreground"
                        >
                            <span
                                class="inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground"
                                :class="feature.tone"
                            >
                                <component
                                    :is="feature.icon"
                                    class="size-3"
                                    stroke-width="2.25"
                                />
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 leading-tight"
                            >
                                <span>{{ feature.label }}</span>
                                <TooltipProvider
                                    v-if="feature.tooltip"
                                    :delay-duration="200"
                                >
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button
                                                type="button"
                                                class="inline-flex size-4 shrink-0 items-center justify-center text-foreground/55 transition-colors hover:text-foreground"
                                                :aria-label="feature.tooltip"
                                                :data-testid="`plan-feature-info-${plan.slug}-${feature.key}`"
                                            >
                                                <IconInfoCircle
                                                    class="size-4"
                                                    stroke-width="2.25"
                                                />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            side="top"
                                            :side-offset="8"
                                            class="max-w-56 rounded-xl p-3 text-start"
                                        >
                                            <p
                                                class="text-xs leading-snug font-medium text-background"
                                            >
                                                {{ feature.tooltip }}
                                            </p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </span>
                        </li>
                    </ul>
                </div>

                <Button
                    type="button"
                    size="lg"
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
