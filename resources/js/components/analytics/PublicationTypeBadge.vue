<script setup lang="ts">
import {
    IconCircleDashedPlus,
    IconCirclePlay,
    IconVideo,
} from '@tabler/icons-vue';
import { computed, type Component } from 'vue';

import { Badge } from '@/components/ui/badge';
import { showsPostTypeTag } from '@/lib/channelInsights';

const props = defineProps<{
    platform: string;
    contentType: string | null;
    testId: string;
}>();

const TYPE_ICONS: Partial<Record<string, Component>> = {
    reel: IconCirclePlay,
    story: IconCircleDashedPlus,
    video: IconVideo,
};

const icon = computed(() =>
    props.contentType && showsPostTypeTag(props.platform)
        ? TYPE_ICONS[props.contentType]
        : undefined,
);
</script>

<template>
    <Badge
        v-if="icon"
        variant="secondary"
        class="h-6 shrink-0 gap-1.5 px-2 [&>svg]:size-4"
        :data-testid="testId"
    >
        <component
            :is="icon"
            class="shrink-0"
            aria-hidden="true"
            :data-testid="`${testId}-icon`"
        />
        {{ $t(`analytics.detail.content_types.${contentType}`) }}
    </Badge>
</template>
