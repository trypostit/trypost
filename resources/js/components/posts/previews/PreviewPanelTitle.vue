<script setup lang="ts">
import { IconInfoCircle } from '@tabler/icons-vue';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

defineProps<{
    title: string;
}>();
</script>

<template>
    <h3
        class="flex shrink-0 items-center gap-3 px-8 pt-[22px] pb-[18px] text-base leading-5 font-medium"
        data-testid="composer-preview-title"
    >
        {{ title }}
        <TooltipProvider :delay-duration="150">
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="rounded-full text-foreground"
                        :aria-label="$t('posts.composer.previews_info')"
                        data-testid="composer-preview-info"
                    >
                        <IconInfoCircle class="size-5" stroke-width="1.75" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom">
                    {{ $t('posts.composer.previews_info') }}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </h3>
</template>
