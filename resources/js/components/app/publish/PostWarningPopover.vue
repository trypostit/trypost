<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * A warning badge on a post that went out with something left off (the
 * Instagram first comment, the Facebook cover), opening its explanation.
 */
defineProps<{
    testId: string;
    testKey: string;
    badge: string;
    title: string;
    description: string;
}>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="rounded-full focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :aria-label="title"
                :data-testid="`${testId}-${testKey}`"
            >
                <Badge variant="warning" class="cursor-pointer" data-single-line>
                    <IconAlertTriangle aria-hidden="true" />
                    {{ badge }}
                </Badge>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            :collision-padding="16"
            class="flex w-80 flex-col gap-2"
            :data-testid="`${testId}-popover-${testKey}`"
        >
            <p class="text-sm font-emphasis text-foreground">
                {{ title }}
            </p>
            <p class="text-sm text-foreground">
                {{ description }}
            </p>
        </PopoverContent>
    </Popover>
</template>
