<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { IconCheck, IconClock, IconX } from '@tabler/icons-vue';

import { Spinner } from '@/components/ui/spinner';
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
    <aside
        class="flex max-h-[40vh] min-h-0 w-full shrink-0 flex-col border-b border-border lg:max-h-none lg:w-80 lg:border-e lg:border-b-0"
        data-testid="webhook-log-list"
    >
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain pb-px">
            <InfiniteScroll
                data="logs"
                items-element="#webhook-logs-body"
                preserve-scroll
            >
                <div id="webhook-logs-body">
                    <button
                        v-for="log in logs"
                        :key="log.id"
                        class="relative flex w-full items-center gap-3 border-b border-border px-4 py-3 text-left transition-colors hover:bg-muted"
                        :class="selectedId === log.id ? 'bg-muted' : ''"
                        type="button"
                        :data-testid="`webhook-log-${log.id}`"
                        @click="$emit('select', log)"
                    >
                        <span
                            v-if="newLogIds.includes(log.id)"
                            class="absolute top-1/2 left-1.5 size-1.5 -translate-y-1/2 animate-pulse rounded-full bg-primary"
                        />
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full"
                            :class="
                                isSuccess(log)
                                    ? 'bg-[var(--success)]/15 text-[var(--success)]'
                                    : isFailed(log)
                                      ? 'bg-[var(--error)]/15 text-[var(--error)]'
                                      : 'bg-muted text-foreground/60'
                            "
                        >
                            <IconCheck v-if="isSuccess(log)" class="size-3.5" />
                            <IconX v-else-if="isFailed(log)" class="size-3.5" />
                            <IconClock v-else class="size-3.5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-sm font-medium text-foreground"
                            >
                                {{ webhookEventLabel(log.event_type) }}
                            </span>
                            <span class="block text-xs text-foreground/60">
                                {{ date.diffForHumans(log.created_at) }}
                            </span>
                        </span>
                    </button>
                </div>

                <template #next="{ loading }">
                    <div v-if="loading" class="flex justify-center py-3">
                        <Spinner />
                    </div>
                </template>
            </InfiniteScroll>
        </div>
    </aside>
</template>
