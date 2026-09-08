export const WebhookStatus = {
    Enabled: 'enabled',
    Disabled: 'disabled',
    Paused: 'paused',
} as const;

export type WebhookStatusValue =
    (typeof WebhookStatus)[keyof typeof WebhookStatus];

type WebhookStatusBadgeVariant = 'default' | 'secondary' | 'warning';

const webhookStatusVariants = {
    [WebhookStatus.Enabled]: 'default',
    [WebhookStatus.Disabled]: 'secondary',
    [WebhookStatus.Paused]: 'warning',
} as const satisfies Record<WebhookStatusValue, WebhookStatusBadgeVariant>;

export const webhookStatusVariant = (
    status: WebhookStatusValue,
): WebhookStatusBadgeVariant => webhookStatusVariants[status];
