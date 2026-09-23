<script setup lang="ts">
import dayjs from '@/dayjs';

import type { WorkspaceAnalyticsReport } from './types';

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
                <h2 class="text-lg leading-tight font-semibold text-foreground">
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle || range"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        range
                            ? `${dayjs(range.start).format('D MMM YYYY')} – ${dayjs(range.end).format('D MMM YYYY')}`
                            : subtitle
                    }}
                </p>
            </div>
            <slot name="actions" />
        </div>
        <slot />
    </section>
</template>
