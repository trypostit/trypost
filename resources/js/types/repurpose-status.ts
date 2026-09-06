export const RepurposeStatus = {
    Draft: 'draft',
    Active: 'active',
    Paused: 'paused',
    Disabled: 'disabled',
} as const;

export type RepurposeStatusValue = (typeof RepurposeStatus)[keyof typeof RepurposeStatus];

/**
 * Why the system stopped a repurpose. NULL means the user paused it, which is
 * what decides whether Resume replays the backlog. It governs the watermark and
 * auto-resume eligibility only — the banner reads current account health.
 */
export const PauseReason = {
    SourceRemoved: 'source_removed',
    SourceUnavailable: 'source_unavailable',
    NoDestinations: 'no_destinations',
} as const;

export type PauseReasonValue = (typeof PauseReason)[keyof typeof PauseReason];

export const RepurposeItemStatus = {
    Pending: 'pending',
    Processing: 'processing',
    Published: 'published',
    Drafted: 'drafted',
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
    [RepurposeItemStatus.Drafted]: 'outline',
    [RepurposeItemStatus.Skipped]: 'secondary',
    [RepurposeItemStatus.Failed]: 'destructive',
} as const satisfies Record<RepurposeItemStatusValue, BadgeVariant>;

export const repurposeStatusVariant = (status: RepurposeStatusValue): BadgeVariant => statusVariants[status];

export const repurposeItemStatusVariant = (status: RepurposeItemStatusValue): BadgeVariant =>
    itemStatusVariants[status];
