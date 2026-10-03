<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';

import { Spinner } from '@/components/ui/spinner';
import date from '@/date';
import type { WebhookLog } from '@/types/webhook';

import { webhookEventLabel } from './webhook-events';
import WebhookDeliveryBadge from './WebhookDeliveryBadge.vue';

defineProps<{
    logs: WebhookLog[];
    selectedId: string | null;
    newLogIds: string[];
}>();

const emit = defineEmits<{
    select: [log: WebhookLog];
}>();

const selectLog = (log: WebhookLog): void => {
    emit('select', log);
};
</script>

<template>
    <aside
        class="flex max-h-[45vh] min-h-0 w-full shrink-0 flex-col border-b border-border lg:max-h-none lg:w-96 lg:border-e lg:border-b-0"
        data-testid="webhook-log-list"
    >
        <h2
            class="shrink-0 px-4 pt-4 pb-2 text-base leading-5 font-emphasis text-foreground md:px-6"
        >
            {{ $t('webhooks.deliveries.title') }}
        </h2>
        <div
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-3 md:px-4"
        >
            <InfiniteScroll
                data="logs"
                items-element="#webhook-logs-body"
                preserve-url
            >
                <ul id="webhook-logs-body" class="flex flex-col gap-1">
                    <li v-for="log in logs" :key="log.id">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start transition-control hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                            :class="{
                                'bg-accent hover:bg-accent': selectedId === log.id,
                            }"
                            :aria-current="selectedId === log.id ? 'true' : undefined"
                            :data-testid="`webhook-log-${log.id}`"
                            @click="selectLog(log)"
                        >
                            <span class="flex min-w-0 flex-1 flex-col gap-1">
                                <span
                                    class="flex min-w-0 items-center gap-2 text-sm leading-tight font-emphasis text-foreground"
                                >
                                    <span class="truncate">{{
                                        webhookEventLabel(log.event_type)
                                    }}</span>
                                    <span
                                        v-if="newLogIds.includes(log.id)"
                                        class="size-1.5 shrink-0 animate-pulse rounded-full bg-primary-strong"
                                    />
                                </span>
                                <span class="truncate text-xs text-muted-foreground">
                                    {{ date.diffForHumans(log.created_at) }}
                                </span>
                            </span>
                            <WebhookDeliveryBadge
                                :log="log"
                                :data-testid="`webhook-log-status-${log.id}`"
                            />
                        </button>
                    </li>
                </ul>

                <template #next="{ loading }">
                    <div v-if="loading" class="flex justify-center py-3">
                        <Spinner />
                    </div>
                </template>
            </InfiniteScroll>
        </div>
    </aside>
</template>
