<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';

import AppLogo from '@/components/AppLogo.vue';

defineProps<{
    benefit: string;
}>();

/**
 * Illustrative numbers under the same labels as the Insights summary, so the
 * analytics example reads like a real report.
 */
const sampleMetrics = [
    { label: 'analytics.metrics.impressions', value: '48.2k' },
    { label: 'analytics.dashboard.reactions', value: '3.1k' },
    { label: 'analytics.dashboard.comments', value: '412' },
    { label: 'analytics.dashboard.engagement_rate', value: '6.4%' },
] as const;
</script>

<template>
    <div class="flex flex-col gap-4">
        <p
            class="ms-12 self-end rounded-2xl rounded-ee-sm bg-card px-3.5 py-2.5 text-sm text-foreground shadow-xs"
        >
            {{ $t(`welcome.mcp.benefits.${benefit}.ask`) }}
        </p>
        <div class="flex flex-col gap-2 pe-6">
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
            <dl
                v-if="benefit === 'analytics'"
                class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border"
                data-testid="welcome-mcp-metrics"
            >
                <div
                    v-for="metric in sampleMetrics"
                    :key="metric.label"
                    class="flex flex-col gap-0.5 bg-card px-2.5 py-2"
                >
                    <dt class="truncate text-xs text-muted-foreground">
                        {{ $t(metric.label) }}
                    </dt>
                    <dd
                        class="text-sm font-semibold text-foreground tabular-nums"
                    >
                        {{ metric.value }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
