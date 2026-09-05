import type { ChannelAccount } from '@/types/channel';
import type { RepurposeItemStatus, RepurposeStatus } from '@/types/repurpose-status';

export type RepurposeSourceFormat = 'reel' | 'video' | 'story';

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

export interface DestinationFormat {
    value: string;
    label: string;
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
    source_account?: ChannelAccount | null;
    destinations: RepurposeDestination[];
    status: RepurposeStatus;
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
    status: string;
}

export interface RepurposeItem {
    id: string;
    source_media_id: string;
    source_permalink: string | null;
    source_created_at: string | null;
    status: RepurposeItemStatus;
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
