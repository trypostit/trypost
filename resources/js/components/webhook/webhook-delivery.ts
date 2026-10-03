import type { WebhookLog } from '@/types/webhook';

export type DeliveryState = 'delivered' | 'failed' | 'pending';

export const deliveryState = (log: WebhookLog): DeliveryState => {
    if (log.delivered_at) {
        return 'delivered';
    }

    if (log.failed_at || (log.response_status ?? 0) >= 300) {
        return 'failed';
    }

    return 'pending';
};

const deliveryBadgeVariants = {
    delivered: 'success',
    failed: 'destructive-subtle',
    pending: 'secondary',
} as const satisfies Record<DeliveryState, string>;

export const deliveryBadgeVariant = (
    log: WebhookLog,
): (typeof deliveryBadgeVariants)[DeliveryState] =>
    deliveryBadgeVariants[deliveryState(log)];
