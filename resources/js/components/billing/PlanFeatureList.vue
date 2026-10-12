<script setup lang="ts">
import {
    IconCalendarEvent,
    IconRefresh,
    IconRobot,
    IconShare,
    IconSparkles,
    IconTrendingUp,
    IconUsers,
    IconWorld,
} from '@tabler/icons-vue';
import type { Component } from 'vue';

import PlanFeatureInfo from '@/components/billing/PlanFeatureInfo.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
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
    { key: 'analytics', icon: IconTrendingUp },
    { key: 'ai', icon: IconSparkles },
    { key: 'mcp', icon: IconRobot },
    { key: 'repurpose', icon: IconRefresh },
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

        <ul :class="['flex flex-col', spacious ? 'gap-1 sm:gap-2.5' : 'gap-1']">
            <li
                :class="[
                    'flex items-start gap-2 text-foreground',
                    spacious
                        ? 'text-sm leading-5 sm:text-base sm:leading-6'
                        : 'text-sm leading-5',
                ]"
            >
                <IconWorld
                    :class="[
                        'size-4 shrink-0 text-muted-foreground',
                        spacious ? 'mt-0.5 sm:mt-1' : 'mt-0.5',
                    ]"
                />
                <span class="inline-flex items-start gap-1.5">
                    <span
                        :data-testid="`plan-feature-label-${scope}-networks_all`"
                        >{{
                            $t('billing.plans.features.networks_all')
                        }}</span
                    >
                    <PlanFeatureInfo
                        :label="$t('billing.plans.features.networks_all_tooltip')"
                        :testid="`plan-networks-info-${scope}`"
                        :spacious="spacious"
                        content-class="max-w-none space-y-2"
                    >
                        <p class="font-medium">
                            {{ $t('billing.plans.features.networks_all_tooltip') }}
                        </p>
                        <div
                            class="grid grid-cols-4 gap-1"
                            :data-testid="`plan-networks-${scope}`"
                        >
                            <span
                                v-for="network in PLAN_NETWORKS"
                                :key="network"
                                class="flex w-16 flex-col items-center gap-1.5 rounded-md bg-muted px-1.5 py-2"
                            >
                                <PlatformLogo
                                    :platform="network"
                                    size="xs"
                                    plain
                                    :title="null"
                                />
                                <span
                                    class="max-w-full truncate text-[10px] leading-none font-medium text-muted-foreground"
                                >
                                    {{ getPlatformLabel(network) }}
                                </span>
                            </span>
                        </div>
                    </PlanFeatureInfo>
                </span>
            </li>

            <li
                v-for="feature in sharedFeatures"
                :key="feature.key"
                :class="[
                    'flex items-start gap-2 text-foreground',
                    spacious
                        ? 'text-sm leading-5 sm:text-base sm:leading-6'
                        : 'text-sm leading-5',
                ]"
            >
                <component
                    :is="feature.icon"
                    :class="[
                        'size-4 shrink-0 text-muted-foreground',
                        spacious ? 'mt-0.5 sm:mt-1' : 'mt-0.5',
                    ]"
                />
                <span class="inline-flex items-start gap-1.5">
                    <span
                        :data-testid="`plan-feature-label-${scope}-${feature.key}`"
                        >{{
                            $t(`billing.plans.features.${feature.key}`)
                        }}</span
                    >
                    <PlanFeatureInfo
                        v-if="feature.hasTooltip"
                        :label="$t(`billing.plans.features.${feature.key}_tooltip`)"
                        :testid="`plan-feature-info-${scope}-${feature.key}`"
                        :spacious="spacious"
                    />
                </span>
            </li>

            <slot name="after" />
        </ul>
    </div>
</template>
