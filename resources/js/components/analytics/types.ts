export interface AnalyticsAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string | null;
    display_label: string | null;
    handle_label: string | null;
    avatar_url: string | null;
}
