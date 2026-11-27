<script setup lang="ts">
import { IconCircleCheckFilled, IconCircleX } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { filled } from '@/lib/helpers';
import type {
    AdminFeaturePermission,
    AdminNetworkPermissions,
    AdminScopePermission,
} from '@/types/admin';

const FEATURE_LABELS: Record<string, string> = {
    publish: 'Publish',
    thread_replies: 'Replies',
    followers: 'Followers',
    post_insights: 'Insights',
    post_insights_extended: 'Extended insights',
    post_history: 'Post history',
    private_post_read: 'Private reads',
    facebook_reel_cover: 'Reel cover',
};

const props = defineProps<{
    network: AdminNetworkPermissions;
}>();

const featureLabel = (feature: string): string => {
    const label = FEATURE_LABELS[feature];

    if (filled(label)) {
        return label;
    }

    return feature;
};

const enabledCount = computed(
    () => props.network.features.filter((feature) => feature.enabled).length,
);

const allEnabled = computed(() => enabledCount.value === props.network.features.length);

const featuresSummary = computed(
    (): string => `${enabledCount.value} of ${props.network.features.length} on`,
);

const scopesSummary = computed((): string => {
    if (props.network.scopes.length === 1) {
        return '1 scope requested';
    }

    return `${props.network.scopes.length} scopes requested`;
});

/** What the scope is for, in words: needed to connect, the features it turns on, or nothing. */
const scopePurpose = (scope: AdminScopePermission): string => {
    const purposes = scope.features.map(featureLabel);

    if (scope.required) {
        purposes.unshift('Needed to connect');
    }

    if (filled(purposes)) {
        return purposes.join(' · ');
    }

    return 'Not used by a feature';
};

const isUnused = (scope: AdminScopePermission): boolean =>
    !scope.required && !filled(scope.features);

const scopeClass = (scope: AdminScopePermission): string => {
    if (isUnused(scope)) {
        return 'text-muted-foreground';
    }

    return 'text-foreground';
};

const featureClass = (feature: AdminFeaturePermission): string => {
    if (feature.enabled) {
        return 'text-foreground';
    }

    return 'text-muted-foreground';
};

const featureTooltip = (feature: AdminFeaturePermission): string => {
    if (feature.enabled) {
        return 'On: the connect asks for every scope it needs.';
    }

    return `Off: the connect does not ask for ${feature.missing.join(', ')}.`;
};
</script>

<template>
    <article
        class="mb-4 flex break-inside-avoid flex-col overflow-hidden rounded-xl border border-border bg-card"
        :data-testid="`admin-permissions-${network.platform}`"
    >
        <header class="flex items-center gap-3 px-4 py-4 sm:px-5">
            <PlatformLogo :platform="network.platform" :size="32" />
            <div class="grid min-w-0 flex-1">
                <h3 class="truncate text-sm font-emphasis text-foreground">
                    {{ network.label }}
                </h3>
                <p class="truncate text-xs text-muted-foreground">{{ scopesSummary }}</p>
            </div>
            <Badge v-if="allEnabled" variant="success">{{ featuresSummary }}</Badge>
            <Badge v-else variant="warning">{{ featuresSummary }}</Badge>
        </header>

        <p
            class="border-y border-border bg-muted/60 px-4 py-2 text-xs font-medium text-muted-foreground sm:px-5"
        >
            Features
        </p>
        <ul class="flex flex-wrap gap-x-4 gap-y-2 px-4 py-3 sm:px-5">
            <li v-for="feature in network.features" :key="feature.feature">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span
                            class="inline-flex cursor-default items-center gap-1.5 text-sm"
                            :class="featureClass(feature)"
                            :data-testid="`admin-permissions-${network.platform}-${feature.feature}`"
                        >
                            <IconCircleCheckFilled
                                v-if="feature.enabled"
                                class="size-4 text-success-text"
                            />
                            <IconCircleX v-else class="size-4" />
                            {{ featureLabel(feature.feature) }}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        {{ featureTooltip(feature) }}
                    </TooltipContent>
                </Tooltip>
            </li>
        </ul>

        <p
            class="border-y border-border bg-muted/60 px-4 py-2 text-xs font-medium text-muted-foreground sm:px-5"
        >
            Scopes
        </p>
        <ul class="divide-y divide-border">
            <li
                v-for="scope in network.scopes"
                :key="scope.scope"
                class="grid gap-0.5 px-4 py-2.5 sm:px-5"
            >
                <code class="font-mono text-xs break-all" :class="scopeClass(scope)">{{
                    scope.scope
                }}</code>
                <span class="text-xs text-muted-foreground">{{ scopePurpose(scope) }}</span>
            </li>
        </ul>
    </article>
</template>
