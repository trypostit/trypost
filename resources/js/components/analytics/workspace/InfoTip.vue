<script setup lang="ts">
import { IconInfoCircle } from '@tabler/icons-vue';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

defineProps<{
    text: string;
    testId?: string;
}>();
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :aria-label="text"
                    :data-testid="testId"
                >
                    <IconInfoCircle class="size-4" aria-hidden="true" />
                </button>
            </TooltipTrigger>
            <TooltipContent class="max-w-64" :data-testid="testId ? `${testId}-content` : undefined">
                {{ text }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
