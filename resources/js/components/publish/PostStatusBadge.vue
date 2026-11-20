<script setup lang="ts">
import { computed } from 'vue';

import { Badge } from '@/components/ui/badge';
import {
    getPostStatusConfig,
    type PostDisplayStatus,
} from '@/composables/usePostStatus';

/**
 * A post's status as a badge: its color, icon and short label come from
 * `usePostStatus`. `compact` is the smaller badge of a dense card (the board);
 * the label is the same at both sizes.
 */
const props = withDefaults(
    defineProps<{
        status: PostDisplayStatus;
        compact?: boolean;
    }>(),
    { compact: false },
);

const config = computed(() => getPostStatusConfig(props.status));

const sizeClass = computed((): string => {
    if (props.compact) {
        return 'h-5 gap-1 px-1.5 [&>svg]:size-3.5';
    }

    return 'h-6 gap-1 px-2 [&>svg]:size-4';
});
</script>

<template>
    <Badge :variant="config.variant" :class="sizeClass">
        <component
            :is="config.icon"
            :class="config.iconClass"
            aria-hidden="true"
        />
        {{ $t(config.labelKey) }}
    </Badge>
</template>
