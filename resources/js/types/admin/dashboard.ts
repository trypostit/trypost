import type { AdminPublishingCounts, AdminPublishingDay } from '@/types/admin/posts';
import type { QuotaSource } from '@/types/admin/quotas';

export interface AdminDashboard {
    attention: {
        networks: {
            platform: string;
            label: string;
            network: string;
            failed: number;
            failure_rate: number;
        }[];
        stuck_publishing: number;
        quotas: { source: QuotaSource; label: string; detail: string }[];
    };
    last_24h: AdminPublishingCounts;
    queue: { scheduled: number; publishing: number; retrying: number; pending_review: number };
    publishing_days: AdminPublishingDay[];
    channels: { total: number; last_7_days: number; connected: number; to_reconnect: number };
}
