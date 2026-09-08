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
    /** Resolved copy for an info tooltip next to the label, when the feature needs one. */
    tooltip?: string;
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

const SHARED_FEATURES: Omit<PlanFeature, 'label' | 'tooltip'>[] = [
    { key: 'accounts_unlimited', icon: IconShare, tone: 'bg-sky-200' },
    { key: 'calendar', icon: IconCalendarEvent, tone: 'bg-blue-200' },
    { key: 'ai', icon: IconSparkles, tone: 'bg-pink-200' },
    { key: 'mcp', icon: IconRobot, tone: 'bg-violet-200' },
    { key: 'repurpose', icon: IconRefresh, tone: 'bg-amber-200' },
    { key: 'analytics', icon: IconChartBar, tone: 'bg-emerald-200' },
    { key: 'team', icon: IconUsers, tone: 'bg-purple-200' },
];

/** Features whose label alone leaves a question open, so they get an info tooltip. */
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

const isCurrent = (plan: PlanOption): boolean =>
    plan.id === props.currentPlanId;

const isDisabled = (plan: PlanOption): boolean =>
    props.processing ||
    isCurrent(plan) ||
    props.disabledPlanIds.includes(plan.id);

const isFeatured = (plan: PlanOption): boolean => plan.slug === 'workspaces';

const firstMonthPrice = (): string =>
    trans('billing.subscribe.prices.first_month');

const billingNote = (plan: PlanOption): string =>
    props.interval === 'yearly'
        ? trans('billing.plans.billed_yearly_total', {
              price: yearlyTotal(plan),
          })
        : trans('billing.subscribe.billed_monthly');

const selectLabel = (plan: PlanOption): string =>
    props.offerFirstMonth
        ? trans('billing.plans.start_first_month', { price: firstMonthPrice() })
        : trans('billing.plans.select', { plan: plan.name });

const isUnlimited = (plan: PlanOption): boolean =>
    plan.workspace_limit === null;

/**
 * The one thing that differs between plans. It gets its own callout so the
 * eye lands on it before the shared feature list.
 */
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
        <div v-if="allowYearly" class="flex justify-center">
            <div
                class="inline-flex rounded-full border-2 border-foreground bg-card p-1 shadow-2xs"
            >
                <Button
                    v-for="option in ['monthly', 'yearly'] as const"
                    :key="option"
                    type="button"
                    size="sm"
                    :variant="interval === option ? 'default' : 'ghost'"
                    class="rounded-full"
                    :data-testid="`plan-interval-${option}`"
                    @click="emit('update:interval', option)"
                >
                    {{ $t(`billing.plans.${option}`) }}
                    <Badge
                        v-if="option === 'yearly'"
                        variant="success"
                        class="ml-1.5"
                    >
                        {{ $t('billing.plans.save_two_months') }}
                    </Badge>
                </Button>
            </div>
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
                <div class="space-y-2">
                    <Badge v-if="isCurrent(plan)" variant="secondary">
                        {{ $t('billing.plans.current') }}
                    </Badge>

                    <div class="space-y-0.5">
                        <h3 class="text-xl font-bold tracking-tight">
                            {{ plan.name }}
                        </h3>
                        <p class="text-sm text-foreground/70">
                            {{ tagline(plan) }}
                        </p>
                    </div>

                    <p>
                        <span
                            class="text-3xl font-bold text-foreground tabular-nums"
                            >{{ price(plan) }}</span
                        >
                        <span class="ml-1 text-foreground/70">{{
                            $t('billing.plans.per_month')
                        }}</span>
                    </p>
                    <p
                        v-if="!offerFirstMonth"
                        class="text-xs font-medium text-foreground/60"
                    >
                        {{ billingNote(plan) }}
                    </p>
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
                    <p class="text-base font-bold text-foreground">
                        {{ workspaceLabel(plan) }}
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
