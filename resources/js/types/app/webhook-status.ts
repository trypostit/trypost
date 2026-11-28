export const WebhookStatus = {
    Enabled: 'enabled',
    Disabled: 'disabled',
    Paused: 'paused',
} as const;

export type WebhookStatusValue =
    (typeof WebhookStatus)[keyof typeof WebhookStatus];

const webhookStatusDots = {
    [WebhookStatus.Enabled]: 'bg-success',
    [WebhookStatus.Paused]: 'bg-warning',
    [WebhookStatus.Disabled]: 'bg-border-strong',
} as const satisfies Record<WebhookStatusValue, string>;

export const webhookStatusDot = (status: WebhookStatusValue): string =>
    webhookStatusDots[status];
