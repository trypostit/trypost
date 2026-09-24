<script setup lang="ts">
import { IconWebhook } from '@tabler/icons-vue';

import EmptyState from '@/components/EmptyState.vue';
import WebhookLogDetail from '@/components/webhook/WebhookLogDetail.vue';
import WebhookLogList from '@/components/webhook/WebhookLogList.vue';
import { useWebhookLogs } from '@/composables/useWebhookLogs';
import type { WebhookLog } from '@/types/webhook';

const props = defineProps<{
    webhookId: string;
    logs: WebhookLog[];
}>();

const { liveLogs, selectedLog, newLogIds, selectLog } = useWebhookLogs(
    () => props.webhookId,
    () => props.logs,
);
</script>

<template>
    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        <div
            v-if="liveLogs.length > 0"
            class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden lg:flex-row"
            data-testid="webhook-log-viewer"
        >
            <WebhookLogList
                :logs="liveLogs"
                :selected-id="selectedLog?.id ?? null"
                :new-log-ids="newLogIds"
                @select="selectLog"
            />
            <WebhookLogDetail
                v-if="selectedLog"
                :key="selectedLog.id"
                :webhook-id="webhookId"
                :log="selectedLog"
            />
        </div>

        <EmptyState
            v-else
            :icon="IconWebhook"
            :title="$t('webhooks.show.empty_title')"
            :description="$t('webhooks.show.empty_description')"
        />
    </div>
</template>
