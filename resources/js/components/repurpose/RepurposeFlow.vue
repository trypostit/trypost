<script setup lang="ts">
import { IconArrowRight } from '@tabler/icons-vue';

import PlatformLogo from '@/components/repurpose/PlatformLogo.vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { FlowNode } from '@/types/repurpose';

/**
 * The one-glance summary of a repurpose: where the video comes from and where
 * it lands. Each logo names its account on hover, because a workspace can hold
 * several accounts on the same network and the logos alone would not say which
 * one is in the flow.
 */
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
                <span>
                    <PlatformLogo :platform="source.platform" :size="size" />
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <span class="font-semibold">{{ getPlatformLabel(source.platform) }}</span>
                <span v-if="source.label"> — {{ source.label }}</span>
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
                    <span class="font-semibold">{{ getPlatformLabel(destination.platform) }}</span>
                    <span v-if="destination.label"> — {{ destination.label }}</span>
                    <span v-if="destination.format" class="block text-xs opacity-80">
                        {{ destination.format }}
                    </span>
                </TooltipContent>
            </Tooltip>
        </div>

        <span v-else class="text-sm text-muted-foreground">
            {{ $t('repurposes.flow.no_destinations') }}
        </span>
    </div>
</template>
