import type { SocialAccountStatusValue } from '@/types/social-account-status';

export interface AvailablePlatform {
    value: string;
    label: string;
    network: string;
    connect_methods?: string[];
}

export interface ConnectedAccount {
    id: string;
    platform: string;
    network: string;
    username: string;
    display_name: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
    profile_url?: string | null;
    status: SocialAccountStatusValue | null;
    is_active?: boolean;
}

export const isConnectionLost = (account: ConnectedAccount): boolean =>
    account.status === 'disconnected' || account.status === 'token_expired';
