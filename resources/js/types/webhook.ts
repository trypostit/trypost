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

export interface WebhookLog {
    id: string;
    event_type: string;
    payload: Record<string, unknown> | null;
    response_status: number | null;
    response_body: string | null;
    delivered_at: string | null;
    failed_at: string | null;
    attempts: number;
    created_at: string;
}
