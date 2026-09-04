import { reactive, ref, toValue, watch, type MaybeRefOrGetter } from 'vue';

import { useWebhookEcho } from '@/composables/echo/useWebhookEcho';
import type { WebhookLog } from '@/types/webhook';

type LiveFields = Pick<
    WebhookLog,
    'response_status' | 'response_body' | 'delivered_at' | 'failed_at' | 'attempts'
>;

const liveFields = (log: WebhookLog): LiveFields => ({
    response_status: log.response_status,
    response_body: log.response_body,
    delivered_at: log.delivered_at,
    failed_at: log.failed_at,
    attempts: log.attempts,
});

const mergeIncomingLog = (incoming: WebhookLog, local?: WebhookLog): WebhookLog => {
    if (!local) {
        return incoming;
    }

    const incomingDelivered = Boolean(incoming.delivered_at);
    const localDelivered = Boolean(local.delivered_at);
    const incomingFailed = Boolean(incoming.failed_at);
    const localFailed = Boolean(local.failed_at);

    if (incomingDelivered && !localDelivered) {
        return incoming;
    }

    if (
        (localDelivered && !incomingDelivered)
        || local.attempts > incoming.attempts
        || (localFailed && !incomingFailed && !incomingDelivered)
    ) {
        return { ...incoming, ...liveFields(local) };
    }

    return incoming;
};

export const useWebhookLogs = (
    webhookId: MaybeRefOrGetter<string>,
    incomingLogs: MaybeRefOrGetter<WebhookLog[]>,
) => {
    const newLogIds = reactive(new Set<string>());
    const liveLogs = ref<WebhookLog[]>([...toValue(incomingLogs)]);
    const selectedLog = ref<WebhookLog | null>(liveLogs.value[0] ?? null);

    const applyLogUpdate = (incoming: WebhookLog): void => {
        const index = liveLogs.value.findIndex((log) => log.id === incoming.id);
        const existing = liveLogs.value[index];
        const next = existing ? { ...existing, ...liveFields(incoming) } : incoming;

        if (existing) {
            liveLogs.value[index] = next;
        } else {
            liveLogs.value.unshift(next);
            newLogIds.add(next.id);
        }

        if (!selectedLog.value || selectedLog.value.id === next.id) {
            selectedLog.value = next;
        }
    };

    useWebhookEcho(toValue(webhookId), '.webhook.log.updated', applyLogUpdate);

    watch(
        () => toValue(incomingLogs),
        (incoming) => {
            const localById = new Map(liveLogs.value.map((log) => [log.id, log]));
            const incomingIds = new Set(incoming.map((log) => log.id));
            const merged = incoming.map((log) => mergeIncomingLog(log, localById.get(log.id)));
            const echoOnly = liveLogs.value.filter((log) => !incomingIds.has(log.id));

            liveLogs.value = [...echoOnly, ...merged];

            const selectedId = selectedLog.value?.id;
            const stillSelected = selectedId
                ? liveLogs.value.find((log) => log.id === selectedId)
                : undefined;

            selectedLog.value = stillSelected ?? liveLogs.value[0] ?? null;
        },
        { deep: true },
    );

    const selectLog = (log: WebhookLog): void => {
        selectedLog.value = log;
        newLogIds.delete(log.id);
    };

    return {
        liveLogs,
        selectedLog,
        newLogIds,
        selectLog,
    };
};
