import type { ChannelAccount } from '@/types/channel';
import type { RepurposeItemStatusValue, RepurposeStatusValue } from '@/types/repurpose-status';

export type RepurposeSourceFormat = 'reel' | 'video' | 'story';

export type RepurposePublishMode = 'publish' | 'draft';

export interface PublishModeOption {
    value: RepurposePublishMode;
    label: string;
    description: string;
}

export interface SourceFormatOption {
    value: RepurposeSourceFormat;
    label: string;
}

export interface FlowNode {
    platform: string;
    label?: string | null;
    username?: string | null;
    format?: string | null;
}

export interface RepurposeDestination {
    social_account_id: string;
    content_type: string;
    meta: Record<string, any>;
}

export interface Repurpose {
    id: string;
    source_social_account_id: string;
    source_format: RepurposeSourceFormat;
    publish_mode: RepurposePublishMode;
    source_account?: ChannelAccount | null;
    destinations: RepurposeDestination[];
    status: RepurposeStatusValue;
    activated_at: string | null;
    last_polled_at: string | null;
    next_poll_at: string | null;
    last_error: string | null;
    published_items_count?: number;
    created_at: string;
    updated_at: string;
}

export interface RepurposeItemPost {
    id: string;
    platform: string | null;
}

export interface RepurposeItem {
    id: string;
    source_media_id: string;
    source_permalink: string | null;
    source_created_at: string | null;
    status: RepurposeItemStatusValue;
    reason: string | null;
    error: string | null;
    posts?: RepurposeItemPost[];
    created_at: string;
}

export interface RepurposeTemplate {
    key: string;
    source_platform: string;
    destination_platforms: string[];
}
