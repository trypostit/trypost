<script setup lang="ts">
import { IconCircleCheckFilled, IconCircleX } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { countLabel } from '@/lib/adminFormat';
import { featureLabel } from '@/lib/adminPermissions';
import { blank, filled } from '@/lib/helpers';
import type {
    AdminFeaturePermission,
    AdminNetworkPermissions,
    AdminScopePermission,
} from '@/types/admin';

const props = defineProps<{
    network: AdminNetworkPermissions;
}>();

const enabledCount = computed(
    () => props.network.features.filter((feature) => feature.enabled).length,
);

const allEnabled = computed(() => enabledCount.value === props.network.features.length);

const featuresSummary = computed(
    (): string => `${enabledCount.value} of ${props.network.features.length} on`,
);

const scopesSummary = computed((): string =>
    countLabel(props.network.scopes.length, 'scope requested', 'scopes requested'),
);

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

/** A scope neither connecting nor any feature needs. */
const isUnused = (scope: AdminScopePermission): boolean => {
    if (scope.required) {
        return false;
    }

    return blank(scope.features);
};

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
                        <button
                            type="button"
                            class="inline-flex cursor-default items-center gap-1.5 rounded-sm text-sm outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                            :class="featureClass(feature)"
                            :data-testid="`admin-permissions-${network.platform}-${feature.feature}`"
                        >
                            <IconCircleCheckFilled
                                v-if="feature.enabled"
                                class="size-4 text-success-text"
                                aria-hidden="true"
                            />
                            <IconCircleX v-else class="size-4" aria-hidden="true" />
                            {{ featureLabel(feature.feature) }}
                        </button>
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
