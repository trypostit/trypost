<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';

import PlatformLogo from '@/components/PlatformLogo.vue';

defineProps<{
    platform: string;
    anchorId: string;
    text: string;
    issueCount: number;
    issueLabel: string;
}>();

const emit = defineEmits<{ open: [] }>();
</script>

<template>
    <button
        type="button"
        :data-testid="`composer-expand-${anchorId}`"
        class="flex min-h-[50px] w-full items-center gap-3 rounded-xl border bg-card px-3 py-1 text-start transition-control hover:bg-accent"
        @click="emit('open')"
    >
        <PlatformLogo :platform="platform" :size="24" class="shrink-0" />
        <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">{{
            text
        }}</span>
        <span
            v-if="issueCount > 0"
            :data-testid="`composer-issues-${anchorId}`"
            class="flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300"
            :aria-label="issueLabel"
        >
            <IconAlertTriangle class="size-3.5" />
            {{ issueCount }}
        </span>
    </button>
</template>
