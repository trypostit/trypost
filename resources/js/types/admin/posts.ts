import type { MediaType } from '@/lib/mediaType';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

/** Where a post was made (`App\Enums\Post\CreatedVia`). */
export type PostCreatedVia = 'web' | 'api' | 'mcp' | 'repurpose';

export interface AdminPublishingDay {
    date: string;
    published: number;
    failed: number;
}

export interface AdminPublishingCounts {
    published: number;
    failed: number;
    failure_rate: number;
}

export interface AdminPublishingStats {
    days: AdminPublishingDay[];
    totals: AdminPublishingCounts;
    networks: (AdminPublishingCounts & { platform: string })[];
    top_errors: {
        platform: string;
        network: string;
        category: string;
        message: string;
        count: number;
    }[];
    categories: { category: string; count: number }[];
    origins: (AdminPublishingCounts & { origin: string })[];
    content_types: (AdminPublishingCounts & {
        platform: string;
        platform_label: string;
        content_type: string;
        label: string;
    })[];
}

export interface AdminFailedPost {
    id: string;
    platform: string;
    channel_name: string | null;
    workspace_name: string | null;
    owner_id: string | null;
    owner_name: string | null;
    channel_status: SocialAccountStatusValue | null;
    content_type: string | null;
    content_type_label: string | null;
    created_via: PostCreatedVia | null;
    content: string;
    media: { url: string | null; type: MediaType | null; mime_type: string | null }[];
    error_message: string | null;
    error_category: string;
    error_context: Record<string, unknown> | null;
    scheduled_at: string | null;
    failed_at: string | null;
    retryable: boolean;
    needs_instagram_confirmation: boolean;
}
