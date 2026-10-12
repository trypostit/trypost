<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import type { Component } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import McpFollowersMockup from '@/components/welcome/mcp/McpFollowersMockup.vue';
import McpIdeasMockup from '@/components/welcome/mcp/McpIdeasMockup.vue';
import McpQueueMockup from '@/components/welcome/mcp/McpQueueMockup.vue';
import McpScheduleMockup from '@/components/welcome/mcp/McpScheduleMockup.vue';

defineProps<{
    benefit: string;
}>();

const mockups: Record<string, Component> = {
    schedule: McpScheduleMockup,
    analytics: McpFollowersMockup,
    queue: McpQueueMockup,
    ideas: McpIdeasMockup,
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <p
            class="ms-12 self-end rounded-2xl rounded-ee-sm bg-card px-3.5 py-2.5 text-sm text-foreground shadow-xs"
        >
            {{ $t(`welcome.mcp.benefits.${benefit}.ask`) }}
        </p>
        <div class="flex flex-col gap-2">
            <span
                class="inline-flex items-center gap-1.5 self-start rounded-md border border-border bg-card px-2 py-1 text-xs font-medium text-foreground"
            >
                <AppLogo variant="mark" class="text-[12px]" />
                TryPost
                <IconCheck class="size-3.5 text-green-500" />
            </span>
            <p class="text-sm text-foreground">
                {{ $t(`welcome.mcp.benefits.${benefit}.reply`) }}
            </p>
            <component :is="mockups[benefit]" class="mt-1" />
        </div>
    </div>
</template>
