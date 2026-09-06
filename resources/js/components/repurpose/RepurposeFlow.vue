<script setup lang="ts">
import { IconArrowRight } from '@tabler/icons-vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { FlowNode } from '@/types/repurpose';

withDefaults(
    defineProps<{
        source: FlowNode;
        destinations: FlowNode[];
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { size: 'md' },
);
</script>

<template>
    <div class="flex items-center justify-center gap-3">
        <Tooltip>
            <TooltipTrigger as-child>
                <span :data-testid="`flow-source-${source.platform}`">
                    <PlatformLogo :platform="source.platform" :size="size" />
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <div class="space-y-0.5 text-xs">
                    <p class="font-semibold">
                        {{ source.label }}<span v-if="source.username" class="font-normal opacity-80">&nbsp;·&nbsp;@{{ source.username }}</span>
                    </p>
                    <p class="opacity-70">{{ getPlatformLabel(source.platform) }}</p>
                </div>
            </TooltipContent>
        </Tooltip>

        <IconArrowRight class="size-4 shrink-0 text-foreground/40" />

        <div v-if="destinations.length > 0" class="flex items-center gap-2">
            <Tooltip v-for="(destination, index) in destinations" :key="`${destination.platform}-${index}`">
                <TooltipTrigger as-child>
                    <span>
                        <PlatformLogo :platform="destination.platform" :size="size" :tilt="false" />
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    <div class="space-y-0.5 text-xs">
                        <p class="font-semibold">
                            {{ destination.label }}<span v-if="destination.username" class="font-normal opacity-80">&nbsp;·&nbsp;@{{ destination.username }}</span>
                        </p>
                        <p class="opacity-70">
                            {{ getPlatformLabel(destination.platform) }}<span v-if="destination.format">&nbsp;·&nbsp;{{ destination.format }}</span>
                        </p>
                    </div>
                </TooltipContent>
            </Tooltip>
        </div>

        <span v-else class="text-sm text-muted-foreground">
            {{ $t('repurposes.flow.no_destinations') }}
        </span>
    </div>
</template>
