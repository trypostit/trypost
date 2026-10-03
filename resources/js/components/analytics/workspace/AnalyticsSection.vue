<script setup lang="ts">
import date from '@/date';
import type { WorkspaceAnalyticsReport } from '@/types/analytics';

import InfoTip from './InfoTip.vue';

defineProps<{
    title: string;
    info?: string;
    infoTestid?: string;
    subtitle?: string;
    subtitleTestid?: string;
    range?: WorkspaceAnalyticsReport['range'];
}>();
</script>

<template>
    <section
        class="min-w-0 rounded-xl bg-muted p-2"
        data-testid="analytics-section"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-x-2 gap-y-2 px-2 pt-2 pb-3"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <div class="-my-0.5 flex min-w-0 items-center gap-1">
                    <h2
                        class="text-base leading-5 font-emphasis text-foreground"
                        data-testid="analytics-section-title"
                    >
                        {{ title }}
                    </h2>
                    <InfoTip v-if="info" :text="info" :test-id="infoTestid" />
                </div>
                <p
                    v-if="subtitle || range"
                    class="text-xs text-muted-foreground"
                    :data-testid="subtitleTestid"
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
