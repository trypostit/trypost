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
    <section
        class="rounded-xl border-2 border-foreground bg-card p-5 shadow-sm sm:p-6"
    >
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <h2
                    class="text-2xl leading-tight font-semibold text-foreground"
                    style="font-family: var(--font-display)"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="subtitle || range"
                    class="text-sm text-muted-foreground"
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
