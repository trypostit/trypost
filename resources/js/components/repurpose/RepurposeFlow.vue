<script setup lang="ts">
import { IconArrowRight, IconQuestionMark } from '@tabler/icons-vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { FlowNode } from '@/types/repurpose';

withDefaults(
    defineProps<{
        source: FlowNode;
        destinations: FlowNode[];
        size?: 'sm' | 'md' | 'lg';
        align?: 'start' | 'center';
    }>(),
    { size: 'md', align: 'center' },
);
</script>

<template>
    <div class="flex items-center gap-3" :class="align === 'start' ? 'justify-start' : 'justify-center'">
        <Tooltip>
            <TooltipTrigger as-child>
                <span :data-testid="`flow-source-${source.platform}`">
                    <span
                        v-if="!source.platform"
                        class="flex size-8 items-center justify-center rounded-lg bg-muted text-foreground/40"
                        data-testid="flow-source-missing"
                    >
                        <IconQuestionMark class="size-4" />
                    </span>

                    <PlatformLogo v-else :platform="source.platform" :size="size" />
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <div class="space-y-0.5 text-xs">
                    <p class="font-semibold">
                        {{ source.label }}<span v-if="source.username" class="font-normal opacity-80">&nbsp;·&nbsp;@{{ source.username }}</span>
                    </p>
                    <p class="opacity-70">
                        {{ source.platform ? getPlatformLabel(source.platform) : $t('repurposes.flow.no_source') }}
                    </p>
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
