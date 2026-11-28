<script setup lang="ts">
import { IconRosetteDiscountCheckFilled } from '@tabler/icons-vue';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { VerifiedBadge } from '@/types/app/social-account';

defineOptions({ inheritAttrs: false });

defineProps<{ badge: VerifiedBadge }>();

const COLORS: Record<VerifiedBadge, string> = {
    blue: 'text-[#1d9bf0]',
    business: 'text-[#e2b719]',
    government: 'text-[#829aab]',
};
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    role="img"
                    :aria-label="$t(`channels.verified.${badge}`)"
                    class="inline-flex shrink-0"
                    :data-verified="badge"
                    v-bind="$attrs"
                >
                    <IconRosetteDiscountCheckFilled
                        :class="['size-full', COLORS[badge]]"
                        aria-hidden="true"
                    />
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {{ $t(`channels.verified.${badge}`) }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
