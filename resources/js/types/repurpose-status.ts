export type RepurposeStatus = 'draft' | 'active' | 'paused' | 'disabled';

export type RepurposeItemStatus = 'pending' | 'processing' | 'published' | 'skipped' | 'failed';

export const repurposeStatusVariant = (status: RepurposeStatus): 'default' | 'secondary' | 'warning' | 'outline' =>
    ({
        draft: 'outline',
        active: 'default',
        paused: 'warning',
        disabled: 'secondary',
    })[status] ?? 'outline';

export const repurposeItemStatusVariant = (
    status: RepurposeItemStatus,
): 'default' | 'secondary' | 'warning' | 'destructive' | 'outline' =>
    ({
        pending: 'outline',
        processing: 'outline',
        published: 'default',
        skipped: 'secondary',
        failed: 'destructive',
    })[status] ?? 'outline';
