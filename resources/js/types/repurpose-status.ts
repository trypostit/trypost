export type RepurposeStatus = 'draft' | 'active' | 'paused' | 'disabled';

export type RepurposeItemStatus = 'pending' | 'processing' | 'published' | 'skipped' | 'failed';

type BadgeVariant = 'default' | 'secondary' | 'warning' | 'destructive' | 'outline';

const STATUS_VARIANTS: Record<RepurposeStatus, BadgeVariant> = {
    draft: 'outline',
    active: 'default',
    paused: 'warning',
    disabled: 'secondary',
};

const ITEM_STATUS_VARIANTS: Record<RepurposeItemStatus, BadgeVariant> = {
    pending: 'outline',
    processing: 'outline',
    published: 'default',
    skipped: 'secondary',
    failed: 'destructive',
};

export const repurposeStatusVariant = (status: RepurposeStatus): BadgeVariant =>
    STATUS_VARIANTS[status] ?? 'outline';

export const repurposeItemStatusVariant = (status: RepurposeItemStatus): BadgeVariant =>
    ITEM_STATUS_VARIANTS[status] ?? 'outline';
