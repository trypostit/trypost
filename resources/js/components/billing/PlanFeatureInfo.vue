<script setup lang="ts">
import { IconInfoCircle } from '@tabler/icons-vue';

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        label: string;
        testid: string;
        spacious?: boolean;
        contentClass?: string;
    }>(),
    { spacious: false, contentClass: '' },
);
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                :class="[
                    'inline-flex size-4 shrink-0 cursor-pointer items-center justify-center text-muted-foreground transition-control hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                    spacious ? 'mt-0.5 sm:mt-1' : 'mt-0.5',
                ]"
                :aria-label="label"
                :data-testid="testid"
            >
                <IconInfoCircle class="size-4" />
            </button>
        </PopoverTrigger>
        <PopoverContent
            side="top"
            :side-offset="8"
            :class="cn('w-auto max-w-64 p-3 text-start text-sm', contentClass)"
        >
            <slot>{{ label }}</slot>
        </PopoverContent>
    </Popover>
</template>
