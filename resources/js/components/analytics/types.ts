export interface AnalyticsAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string | null;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
}
