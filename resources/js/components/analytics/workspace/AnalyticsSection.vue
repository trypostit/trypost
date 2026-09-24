<script setup lang="ts">
import date from '@/date';

import type { WorkspaceAnalyticsReport } from '@/types/analytics';

defineProps<{
    title: string;
    subtitle?: string;
    range?: WorkspaceAnalyticsReport['range'];
}>();
</script>

<template>
    <section class="space-y-4" data-testid="analytics-section">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="space-y-1">
                <h2
                    class="text-lg leading-tight font-semibold text-foreground"
                    data-testid="analytics-section-title"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle || range"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        range
                            ? `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`
                            : subtitle
                    }}
                </p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </section>
</template>
