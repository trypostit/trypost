<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { IconCheck, IconX } from '@tabler/icons-vue';

import date from '@/date';
import type { WebhookLog } from '@/types/webhook';

import { webhookEventLabel } from './webhook-events';

defineProps<{
    logs: WebhookLog[];
    selectedId: string | null;
    newLogIds: string[];
}>();

defineEmits<{
    select: [log: WebhookLog];
}>();

const isSuccess = (log: WebhookLog): boolean => Boolean(log.delivered_at);
const isFailed = (log: WebhookLog): boolean => Boolean(log.failed_at);
</script>

<template>
    <div class="overflow-y-auto border-b-2 border-foreground lg:border-b-0 lg:border-r-2">
        <InfiniteScroll data="logs" preserve-scroll>
            <button
                v-for="log in logs"
                :key="log.id"
                class="relative flex w-full items-center gap-3 border-b-2 border-foreground/10 px-4 py-3 text-left transition-colors hover:bg-violet-50 dark:hover:bg-violet-950/30"
                :class="selectedId === log.id ? 'bg-violet-100 dark:bg-violet-950/40' : ''"
                type="button"
                @click="$emit('select', log)"
            >
                <span
                    v-if="newLogIds.includes(log.id)"
                    class="absolute left-1.5 top-1/2 size-1.5 -translate-y-1/2 animate-pulse rounded-full bg-violet-500"
                />
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-xl border-2 border-foreground shadow-2xs"
                    :class="
                        isSuccess(log)
                            ? 'bg-emerald-200 text-foreground'
                            : isFailed(log)
                              ? 'bg-rose-200 text-foreground'
                              : 'bg-muted text-foreground/60'
                    "
                >
                    <IconCheck v-if="isSuccess(log)" class="size-3.5" stroke-width="2.5" />
                    <IconX v-else-if="isFailed(log)" class="size-3.5" stroke-width="2.5" />
                    <span v-else class="size-1.5 rounded-full bg-current" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-foreground">
                        {{ webhookEventLabel(log.event_type) }}
                    </p>
                    <p class="text-xs font-medium text-foreground/60">
                        {{ date.diffForHumans(log.created_at) }}
                    </p>
                </div>
            </button>
        </InfiniteScroll>
    </div>
</template>
