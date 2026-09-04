import { router } from '@inertiajs/vue3';
import { ref, toValue, watch, type MaybeRefOrGetter } from 'vue';

import { useWebhookEcho } from '@/composables/echo/useWebhookEcho';
import dayjs from '@/dayjs';
import {
    webhookLogFromBroadcast,
    type WebhookLog,
    type WebhookLogBroadcast,
} from '@/types/webhook';

const liveFields = (log: WebhookLogBroadcast): Pick<
    WebhookLogBroadcast,
    'response_status' | 'delivered_at' | 'failed_at' | 'attempts'
> => ({
    response_status: log.response_status,
    delivered_at: log.delivered_at,
    failed_at: log.failed_at,
    attempts: log.attempts,
});

const compareNewestFirst = (left: WebhookLog, right: WebhookLog): number =>
    dayjs(right.created_at).valueOf() - dayjs(left.created_at).valueOf();

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

const syncLogs = (incoming: WebhookLog[], local: WebhookLog[]): WebhookLog[] => {
    const localById = new Map(local.map((log) => [log.id, log]));
    const incomingIds = new Set(incoming.map((log) => log.id));
    const merged = incoming.map((log) => mergeIncomingLog(log, localById.get(log.id)));
    const echoOnly = local.filter((log) => !incomingIds.has(log.id));

    return [...echoOnly, ...merged].sort(compareNewestFirst);
};

export const useWebhookLogs = (
    webhookId: MaybeRefOrGetter<string>,
    incomingLogs: MaybeRefOrGetter<WebhookLog[]>,
) => {
    const newLogIds = ref<string[]>([]);
    const liveLogs = ref<WebhookLog[]>([...toValue(incomingLogs)]);
    const selectedLog = ref<WebhookLog | null>(liveLogs.value[0] ?? null);

    const applyLogUpdate = (incoming: WebhookLogBroadcast): void => {
        const existing = liveLogs.value.find((log) => log.id === incoming.id);
        const next = existing
            ? { ...existing, ...liveFields(incoming) }
            : webhookLogFromBroadcast(incoming);

        liveLogs.value = existing
            ? liveLogs.value.map((log) => (log.id === next.id ? next : log))
            : [next, ...liveLogs.value].sort(compareNewestFirst);

        if (!existing && !newLogIds.value.includes(next.id)) {
            newLogIds.value = [...newLogIds.value, next.id];
        }

        if (!selectedLog.value || selectedLog.value.id === next.id) {
            selectedLog.value = next;
        }

        router.reload({ only: ['logs'], preserveScroll: true });
    };

    useWebhookEcho(toValue(webhookId), '.webhook.log.updated', applyLogUpdate);

    watch(
        () => toValue(incomingLogs),
        (incoming) => {
            liveLogs.value = syncLogs(incoming, liveLogs.value);

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
        newLogIds.value = newLogIds.value.filter((id) => id !== log.id);
    };

    return {
        liveLogs,
        selectedLog,
        newLogIds,
        selectLog,
    };
};
