export const RepurposeStatus = {
    Draft: 'draft',
    Active: 'active',
    Paused: 'paused',
    Disabled: 'disabled',
} as const;

export type RepurposeStatusValue = (typeof RepurposeStatus)[keyof typeof RepurposeStatus];

export const RepurposeItemStatus = {
    Pending: 'pending',
    Processing: 'processing',
    Published: 'published',
    Skipped: 'skipped',
    Failed: 'failed',
} as const;

export type RepurposeItemStatusValue = (typeof RepurposeItemStatus)[keyof typeof RepurposeItemStatus];

type BadgeVariant = 'default' | 'secondary' | 'warning' | 'destructive' | 'outline';

const statusVariants = {
    [RepurposeStatus.Draft]: 'outline',
    [RepurposeStatus.Active]: 'default',
    [RepurposeStatus.Paused]: 'warning',
    [RepurposeStatus.Disabled]: 'secondary',
} as const satisfies Record<RepurposeStatusValue, BadgeVariant>;

const itemStatusVariants = {
    [RepurposeItemStatus.Pending]: 'outline',
    [RepurposeItemStatus.Processing]: 'outline',
    [RepurposeItemStatus.Published]: 'default',
    [RepurposeItemStatus.Skipped]: 'secondary',
    [RepurposeItemStatus.Failed]: 'destructive',
} as const satisfies Record<RepurposeItemStatusValue, BadgeVariant>;

export const repurposeStatusVariant = (status: RepurposeStatusValue): BadgeVariant => statusVariants[status];

export const repurposeItemStatusVariant = (status: RepurposeItemStatusValue): BadgeVariant =>
    itemStatusVariants[status];
