<script setup lang="ts">
import {
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
import type { Component } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { Platform } from '@/types/platform';

interface PlanFeature {
    key: string;
    icon: Component;
    hasTooltip: boolean;
}

withDefaults(
    defineProps<{
        scope: string;
        spacious?: boolean;
    }>(),
    { spacious: false },
);

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
    <div
        class="flex flex-col gap-3"
        :data-testid="`plan-feature-list-${scope}`"
    >
        <p class="text-sm font-medium text-muted-foreground">
            {{ $t('billing.plans.everything_included') }}
        </p>

        <ul :class="['flex flex-col', spacious ? 'gap-2.5' : 'gap-1']">
            <li
                :class="[
                    'flex items-start gap-2 text-foreground',
                    spacious ? 'text-base leading-6' : 'text-sm leading-5',
                ]"
            >
                <IconWorld
                    :class="[
                        'size-4 shrink-0 text-muted-foreground',
                        spacious ? 'mt-1' : 'mt-0.5',
                    ]"
                />
                <span class="inline-flex items-start gap-1.5">
                    <span
                        :data-testid="`plan-feature-label-${scope}-networks_all`"
                        >{{
                            $t('billing.plans.features.networks_all')
                        }}</span
                    >
                    <TooltipProvider :delay-duration="200">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    :class="[
                                        'inline-flex size-4 shrink-0 cursor-pointer items-center justify-center text-muted-foreground transition-control hover:text-foreground',
                                        spacious ? 'mt-1' : 'mt-0.5',
                                    ]"
                                    :aria-label="
                                        $t(
                                            'billing.plans.features.networks_all_tooltip',
                                        )
                                    "
                                    :data-testid="`plan-networks-info-${scope}`"
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
                                    :data-testid="`plan-networks-${scope}`"
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
                :class="[
                    'flex items-start gap-2 text-foreground',
                    spacious ? 'text-base leading-6' : 'text-sm leading-5',
                ]"
            >
                <component
                    :is="feature.icon"
                    :class="[
                        'size-4 shrink-0 text-muted-foreground',
                        spacious ? 'mt-1' : 'mt-0.5',
                    ]"
                />
                <span class="inline-flex items-start gap-1.5">
                    <span
                        :data-testid="`plan-feature-label-${scope}-${feature.key}`"
                        >{{
                            $t(`billing.plans.features.${feature.key}`)
                        }}</span
                    >
                    <TooltipProvider
                        v-if="feature.hasTooltip"
                        :delay-duration="200"
                    >
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    :class="[
                                        'inline-flex size-4 shrink-0 cursor-pointer items-center justify-center text-muted-foreground transition-control hover:text-foreground',
                                        spacious ? 'mt-1' : 'mt-0.5',
                                    ]"
                                    :aria-label="
                                        $t(
                                            `billing.plans.features.${feature.key}_tooltip`,
                                        )
                                    "
                                    :data-testid="`plan-feature-info-${scope}-${feature.key}`"
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

            <slot name="after" />
        </ul>
    </div>
</template>
