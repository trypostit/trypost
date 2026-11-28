import { filled } from '@/lib/helpers';

/** How the admin names each `ErrorCategory` a failed post is filed under. */
const ERROR_CATEGORY_LABELS: Record<string, string> = {
    unknown: 'Unknown',
    server_error: 'Server error',
    content_policy: 'Content policy',
    timeout: 'Timeout',
    rate_limit: 'Rate limit',
    media_format: 'Media format',
    token_expired: 'Token expired',
    permission: 'Permission',
    platform_unavailable: 'Network down',
    job_failed: 'Job failed',
    refused: 'Refused',
};

export const errorCategoryLabel = (category: string): string => {
    const label = ERROR_CATEGORY_LABELS[category];

    if (filled(label)) {
        return label;
    }

    return category;
};
