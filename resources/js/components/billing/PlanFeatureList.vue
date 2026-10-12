<script setup lang="ts">
import {
    IconBulb,
    IconCalendarEvent,
    IconRefresh,
    IconSettingsAi,
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
    { key: 'ideas', icon: IconBulb },
    { key: 'ai', icon: IconSparkles },
    { key: 'calendar', icon: IconCalendarEvent },
    { key: 'analytics', icon: IconTrendingUp },
    { key: 'mcp', icon: IconSettingsAi },
    { key: 'repurpose', icon: IconRefresh },
    { key: 'team', icon: IconUsers },
];

const FEATURES_WITH_TOOLTIP = new Set([
    'accounts_unlimited',
    'ai',
    'analytics',
    'calendar',
    'ideas',
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
                        content-class="w-80 max-w-none space-y-3 p-4"
                    >
                        <p class="font-medium text-foreground">
                            {{ $t('billing.plans.features.networks_all_tooltip') }}
                        </p>
                        <ul
                            class="grid grid-cols-2 gap-x-4 gap-y-2.5"
                            :data-testid="`plan-networks-${scope}`"
                        >
                            <li
                                v-for="(network, index) in PLAN_NETWORKS"
                                :key="network"
                                :class="[
                                    'flex min-w-0 items-center gap-2 text-sm text-foreground',
                                    index === PLAN_NETWORKS.length - 1 &&
                                    PLAN_NETWORKS.length % 2 === 1
                                        ? 'col-span-2'
                                        : '',
                                ]"
                            >
                                <PlatformLogo
                                    :platform="network"
                                    size="xs"
                                    plain
                                    :title="null"
                                />
                                <span class="truncate">
                                    {{ getPlatformLabel(network) }}
                                </span>
                            </li>
                        </ul>
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
