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
    flush?: boolean;
}>();
</script>

<template>
    <section
        class="min-w-0 overflow-hidden rounded-xl border border-border bg-card"
        data-testid="analytics-section"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-4 pt-4 pb-4 sm:px-5"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <div class="-my-0.5 flex min-w-0 items-center gap-1">
                    <h2
                        class="font-heading text-base leading-6 font-medium text-foreground"
                        data-testid="analytics-section-title"
                    >
                        {{ title }}
                    </h2>
                    <InfoTip v-if="info" :text="info" :test-id="infoTestid" />
                </div>
                <p
                    v-if="subtitle || range || $slots.subtitle"
                    class="text-xs text-muted-foreground"
                    :data-testid="subtitleTestid"
                >
                    <slot name="subtitle">
                        {{
                            range
                                ? `${date.formatDayMonthYear(range.start)} – ${date.formatDayMonthYear(range.end)}`
                                : subtitle
                        }}
                    </slot>
                </p>
            </div>
            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
        <div :class="flush ? 'min-w-0' : 'min-w-0 px-4 pb-4 sm:px-5 sm:pb-5'">
            <slot />
        </div>
    </section>
</template>
