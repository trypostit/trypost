export interface AnalyticsAccount {
    id: string;
    account_id: string;
    location_id: string | null;
    platform: string;
    username: string | null;
    display_label: string;
    avatar_url: string | null;
}
