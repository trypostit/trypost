<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

withDefaults(
    defineProps<{
        label: string;
        side?: 'top' | 'right' | 'bottom' | 'left';
        wrap?: boolean;
    }>(),
    { side: 'top', wrap: false },
);
</script>

<template>
    <TooltipProvider :delay-duration="300">
        <Tooltip>
            <TooltipTrigger as-child>
                <span v-if="wrap" class="inline-flex max-w-full min-w-0">
                    <slot />
                </span>
                <slot v-else />
            </TooltipTrigger>
            <TooltipContent :side="side" data-testid="inbox-tooltip">
                {{ label }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
