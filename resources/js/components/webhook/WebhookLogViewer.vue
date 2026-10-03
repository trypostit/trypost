<script setup lang="ts">
import { IconSend } from '@tabler/icons-vue';

import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import WebhookLogDetail from '@/components/webhook/WebhookLogDetail.vue';
import WebhookLogList from '@/components/webhook/WebhookLogList.vue';
import WebhooksEmptyIllustration from '@/components/webhooks/WebhooksEmptyIllustration.vue';
import { useWebhookLogs } from '@/composables/useWebhookLogs';
import type { WebhookLog } from '@/types/webhook';

const props = defineProps<{
    webhookId: string;
    logs: WebhookLog[];
    sendingTest: boolean;
}>();

const emit = defineEmits<{
    sendTest: [];
}>();

const { liveLogs, selectedLog, newLogIds, selectLog } = useWebhookLogs(
    () => props.webhookId,
    () => props.logs,
);

const requestTestEvent = (): void => {
    emit('sendTest');
};
</script>

<template>
    <div
        v-if="liveLogs.length > 0"
        class="flex min-w-0 flex-col border-t border-border lg:min-h-0 lg:flex-1 lg:flex-row lg:overflow-hidden"
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

    <div
        v-else
        class="flex flex-1 flex-col border-t border-border"
    >
        <EmptyState
            :title="$t('webhooks.deliveries.empty_title')"
            :description="$t('webhooks.deliveries.empty_description')"
            data-testid="webhook-deliveries-empty"
        >
            <template #illustration>
                <div class="w-56">
                    <WebhooksEmptyIllustration />
                </div>
            </template>
            <template #action>
                <Button
                    variant="outline"
                    :loading="sendingTest"
                    data-testid="webhook-deliveries-send-test"
                    @click="requestTestEvent"
                >
                    <IconSend class="size-4" />
                    {{ $t('webhooks.actions.send_test') }}
                </Button>
            </template>
        </EmptyState>
    </div>
</template>
