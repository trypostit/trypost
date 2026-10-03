<script setup lang="ts">
import { IconListNumbers, IconPin } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
import { ScheduleMode, type ScheduleModeValue } from '@/types/post';

withDefaults(
    defineProps<{
        postId: string;
        mode: ScheduleModeValue;
        compact?: boolean;
        plain?: boolean;
    }>(),
    { compact: false, plain: false },
);
</script>

<template>
    <span
        v-if="plain"
        class="inline-flex items-center gap-0.5 text-xs text-muted-foreground"
        :data-testid="`post-schedule-mode-${postId}`"
        :data-mode="mode"
    >
        <IconListNumbers v-if="mode === ScheduleMode.Queue" class="size-3" />
        <IconPin v-else class="size-3" />
        <span :class="compact ? 'sr-only' : ''">{{
            $t(`posts.schedule_mode.${mode}`)
        }}</span>
    </span>
    <Badge
        v-else
        variant="outline"
        class="gap-1 bg-background/70"
        :data-testid="`post-schedule-mode-${postId}`"
        :data-mode="mode"
        :title="compact ? $t(`posts.schedule_mode.${mode}`) : undefined"
    >
        <IconListNumbers v-if="mode === ScheduleMode.Queue" />
        <IconPin v-else />
        <span :class="compact ? 'sr-only' : ''">{{
            $t(`posts.schedule_mode.${mode}`)
        }}</span>
    </Badge>
</template>
