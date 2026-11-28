import type { AdminPublishingCounts } from '@/types/admin/posts';
import type { AdminBarRow } from '@/types/admin/shared';
import type { GatedFeatureValue } from '@/types/channel-permission';

export interface AdminNetworkHealth {
    platform: string;
    label: string;
    channels: { connected: number; token_expired: number; disconnected: number };
    last_24h: AdminPublishingCounts;
    last_7d: AdminPublishingCounts;
    last_failure_at: string | null;
}

export interface AdminScopePermission {
    scope: string;
    required: boolean;
    features: GatedFeatureValue[];
}

export interface AdminFeaturePermission {
    feature: GatedFeatureValue;
    enabled: boolean;
    missing: string[];
}

export interface AdminNetworkPermissions {
    platform: string;
    label: string;
    scopes: AdminScopePermission[];
    features: AdminFeaturePermission[];
}

export interface AdminChannelGrowth {
    days: string[];
    by_network: Record<string, number[]>;
    total_by_day: number[];
    new_in_range: number;
    connected_now: number;
}

export interface AdminChannelDistribution {
    totals: { channels: number; accounts: number; first: number };
    channels: AdminBarRow[];
    accounts: (AdminBarRow & { per_account: number })[];
    first: AdminBarRow[];
}
