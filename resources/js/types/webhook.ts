import type { WebhookStatusValue } from '@/types/webhook-status';

export interface Webhook {
    id: string;
    endpoint: string;
    events: string[];
    status: WebhookStatusValue;
    last_sent_at: string | null;
}

export interface WebhookWithSecret extends Webhook {
    signing_secret: string;
}

export interface WebhookLogBroadcast {
    id: string;
    event_type: string;
    response_status: number | null;
    delivered_at: string | null;
    failed_at: string | null;
    attempts: number;
    created_at: string;
}

export interface WebhookLog extends WebhookLogBroadcast {
    payload: Record<string, unknown> | null;
    response_body: string | null;
}

export const webhookLogFromBroadcast = (broadcast: WebhookLogBroadcast): WebhookLog => ({
    ...broadcast,
    payload: null,
    response_body: null,
});
